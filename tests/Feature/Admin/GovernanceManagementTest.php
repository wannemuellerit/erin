<?php

use App\Enums\GdprRequestStatus;
use App\Enums\UserRole;
use App\Models\AccessListEntry;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\GdprRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('exposes real governance records to platform staff with related users', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $subject = User::factory()->create();

    GdprRequest::query()->create([
        'user_id' => $subject->id,
        'handled_by' => $admin->id,
        'type' => 'export',
        'status' => GdprRequestStatus::Processing,
        'reason' => 'Auskunft gemäß Nutzeranfrage',
        'due_at' => now()->addWeek(),
    ]);

    AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'domain',
        'value' => 'blocked.example',
        'reason' => 'Verifizierter Missbrauch',
        'created_by' => $admin->id,
    ]);

    foreach (['de', 'en'] as $locale) {
        EmailTemplate::query()->create([
            'key' => 'support.ticket_answered',
            'locale' => $locale,
            'subject' => "Subject {$locale}",
            'body_html' => "<p>Body {$locale}</p>",
            'body_text' => "Body {$locale}",
            'updated_by' => $admin->id,
        ]);
    }

    $this->actingAs($support)
        ->get(route('admin.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/System')
            ->has('gdpr_requests.data', 1)
            ->where('gdpr_requests.data.0.user.email', $subject->email)
            ->where('gdpr_requests.data.0.handler.email', $admin->email)
            ->has('access_list_entries', 1)
            ->where('access_list_entries.0.creator.email', $admin->email)
            ->has('email_templates', 2)
            ->where('governance.access_list_entries', 1)
            ->where('governance.email_templates', 2));
});

it('keeps every governance mutation restricted to superadmins', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $subject = User::factory()->create();
    $gdprRequest = GdprRequest::query()->create([
        'user_id' => $subject->id,
        'type' => 'export',
        'status' => GdprRequestStatus::Requested,
    ]);
    $accessListEntry = AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'email',
        'value' => 'existing@example.com',
        'reason' => 'Bestehender Plattformmissbrauch',
    ]);
    $emailTemplate = EmailTemplate::query()->create([
        'key' => 'existing.template',
        'locale' => 'de',
        'subject' => 'Bestehend',
        'body_html' => '<p>Bestehend</p>',
    ]);

    $this->actingAs($support)
        ->post(route('admin.gdpr-requests.store'), [
            'user_id' => $subject->id,
            'type' => 'export',
        ])
        ->assertForbidden();

    $this->actingAs($support)
        ->patch(route('admin.gdpr-requests.update', $gdprRequest), [
            'type' => 'delete',
            'status' => GdprRequestStatus::Processing->value,
        ])
        ->assertForbidden();

    $this->actingAs($support)
        ->post(route('admin.access-list.store'), [
            'list_type' => 'blacklist',
            'subject_type' => 'email',
            'value' => 'blocked@example.com',
            'reason' => 'Verifizierter Missbrauch',
        ])
        ->assertForbidden();

    $this->actingAs($support)
        ->patch(route('admin.access-list.update', $accessListEntry), [
            'list_type' => 'whitelist',
            'subject_type' => 'email',
            'value' => 'changed@example.com',
            'reason' => 'Darf nicht verändert werden',
        ])
        ->assertForbidden();

    $this->actingAs($support)
        ->delete(route('admin.access-list.destroy', $accessListEntry))
        ->assertForbidden();

    $this->actingAs($support)
        ->post(route('admin.email-templates.upsert'), erinEmailTemplatePayload())
        ->assertForbidden();

    $this->actingAs($support)
        ->delete(route('admin.email-templates.destroy', $emailTemplate->key))
        ->assertForbidden();

    expect(GdprRequest::query()->count())->toBe(1)
        ->and($gdprRequest->refresh()->status)->toBe(GdprRequestStatus::Requested)
        ->and(AccessListEntry::query()->count())->toBe(1)
        ->and($accessListEntry->refresh()->value)->toBe('existing@example.com')
        ->and(EmailTemplate::query()->count())->toBe(1);
});

it('creates and advances gdpr requests without hard deletion and audits each mutation', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $approver = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.gdpr-requests.store'), [
            'user_id' => $subject->id,
            'type' => 'archive',
        ])
        ->assertSessionHasErrors('type');

    $this->actingAs($admin)
        ->post(route('admin.gdpr-requests.store'), [
            'user_id' => $subject->id,
            'type' => 'delete',
            'reason' => 'Löschanfrage des Nutzers',
            'due_at' => now()->addWeeks(2)->toIso8601String(),
        ])
        ->assertRedirect();

    $gdprRequest = GdprRequest::query()->firstOrFail();

    expect($gdprRequest->status)->toBe(GdprRequestStatus::Requested)
        ->and(AuditLog::query()
            ->where('event', 'admin.gdpr_request.created')
            ->where('auditable_id', $gdprRequest->id)
            ->exists())->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('admin.gdpr-requests.update', $gdprRequest), [
            'type' => 'delete',
            'status' => GdprRequestStatus::Rejected->value,
            'reason' => null,
            'due_at' => null,
        ])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)
        ->patch(route('admin.gdpr-requests.update', $gdprRequest), [
            'type' => 'delete',
            'status' => GdprRequestStatus::Verified->value,
            'reason' => 'Identität wurde geprüft',
            'due_at' => now()->addWeek()->toIso8601String(),
        ])
        ->assertRedirect();

    expect($gdprRequest->refresh()->status)->toBe(GdprRequestStatus::Verified)
        ->and($gdprRequest->handled_by)->toBe($admin->id)
        ->and($gdprRequest->verified_at)->not->toBeNull()
        ->and($gdprRequest->completed_at)->toBeNull();

    $this->actingAs($approver)
        ->patch(route('admin.gdpr-requests.update', $gdprRequest), [
            'type' => 'delete',
            'status' => GdprRequestStatus::Processing->value,
            'reason' => 'Daten wurden pseudonymisiert',
            'due_at' => now()->addWeek()->toIso8601String(),
        ])
        ->assertRedirect();

    expect($gdprRequest->refresh()->status)->toBe(GdprRequestStatus::Completed)
        ->and($gdprRequest->completed_at)->not->toBeNull()
        ->and(GdprRequest::query()->whereKey($gdprRequest->id)->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('event', 'admin.gdpr_request.updated')
            ->where('auditable_id', $gdprRequest->id)
            ->count())->toBe(2);
});

it('validates normalizes updates and deletes access list entries with audit history', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)
        ->post(route('admin.access-list.store'), [
            'list_type' => 'blacklist',
            'subject_type' => 'ip',
            'value' => 'not-an-ip',
            'reason' => 'Ungültiger Zugriffsversuch',
        ])
        ->assertSessionHasErrors('value');

    $payload = [
        'list_type' => 'whitelist',
        'subject_type' => 'domain',
        'value' => 'EXAMPLE.COM.',
        'reason' => 'Vertraglich verifizierter Partner',
        'expires_at' => now()->addMonth()->toIso8601String(),
    ];

    $this->actingAs($admin)
        ->post(route('admin.access-list.store'), $payload)
        ->assertRedirect();

    $entry = AccessListEntry::query()->firstOrFail();

    expect($entry->value)->toBe('example.com')
        ->and($entry->created_by)->toBe($admin->id);

    $this->actingAs($admin)
        ->post(route('admin.access-list.store'), $payload)
        ->assertSessionHasErrors('value');

    $this->actingAs($admin)
        ->patch(route('admin.access-list.update', $entry), [
            'list_type' => 'blacklist',
            'subject_type' => 'email',
            'value' => 'BLOCKED@EXAMPLE.COM',
            'reason' => 'Bestätigter Plattformmissbrauch',
            'expires_at' => now()->addWeeks(2)->toIso8601String(),
        ])
        ->assertRedirect();

    expect($entry->refresh()->list_type)->toBe('blacklist')
        ->and($entry->value)->toBe('blocked@example.com');

    $this->actingAs($admin)
        ->delete(route('admin.access-list.destroy', $entry))
        ->assertRedirect();

    expect(AccessListEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(AuditLog::query()
            ->whereIn('event', [
                'admin.access_list_entry.created',
                'admin.access_list_entry.updated',
                'admin.access_list_entry.deleted',
            ])
            ->where('auditable_id', $entry->id)
            ->count())->toBe(3);
});

it('upserts and deletes bilingual email templates atomically with validation and audit', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $invalid = erinEmailTemplatePayload();
    unset($invalid['translations']['en']);

    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), $invalid)
        ->assertSessionHasErrors('translations.en');

    $payload = erinEmailTemplatePayload();

    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), $payload)
        ->assertRedirect();

    expect(EmailTemplate::query()
        ->where('key', 'application.accepted')
        ->count())->toBe(2)
        ->and(EmailTemplate::query()
            ->where('key', 'application.accepted')
            ->pluck('locale')
            ->all())->toEqualCanonicalizing(['de', 'en']);

    $payload['is_active'] = false;
    $payload['translations']['de']['subject'] = 'Bewerbung aktualisiert';

    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), $payload)
        ->assertRedirect();

    expect(EmailTemplate::query()
        ->where('key', 'application.accepted')
        ->where('locale', 'de')
        ->firstOrFail())
        ->subject->toBe('Bewerbung aktualisiert')
        ->is_active->toBeFalse();

    $this->actingAs($admin)
        ->delete(route('admin.email-templates.destroy', 'application.accepted'))
        ->assertRedirect();

    expect(EmailTemplate::query()
        ->where('key', 'application.accepted')
        ->exists())->toBeFalse()
        ->and(AuditLog::query()
            ->where('event', 'admin.email_template.upserted')
            ->count())->toBe(2)
        ->and(AuditLog::query()
            ->where('event', 'admin.email_template.deleted')
            ->count())->toBe(1);
});

/**
 * @return array<string, mixed>
 */
function erinEmailTemplatePayload(): array
{
    return [
        'key' => 'application.accepted',
        'is_active' => true,
        'translations' => [
            'de' => [
                'subject' => 'Bewerbung angenommen',
                'body_html' => '<p>Deine Bewerbung wurde angenommen.</p>',
                'body_text' => 'Deine Bewerbung wurde angenommen.',
            ],
            'en' => [
                'subject' => 'Application accepted',
                'body_html' => '<p>Your application was accepted.</p>',
                'body_text' => 'Your application was accepted.',
            ],
        ],
    ];
}
