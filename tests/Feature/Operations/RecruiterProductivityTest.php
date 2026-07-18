<?php

use App\Console\Commands\SendDueRecruiterReminders;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\ActivityEntry;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobPosting;
use App\Models\RecruiterReminder;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function erinReminderEmployer(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return compact('user', 'company');
}

it('creates recruiter reminders only for the active tenant and valid company targets', function () {
    ['user' => $owner, 'company' => $company] = erinReminderEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinReminderEmployer();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $foreignJob = JobPosting::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignOwner->getKey(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.reminders.store'), [
            'title' => 'Freitag zurückrufen',
            'note' => 'Nach dem aktuellen Stand fragen.',
            'priority' => 'high',
            'due_at' => now()->addDay()->toIso8601String(),
            'assignee_id' => $owner->getKey(),
            'job_posting_id' => $job->getKey(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $reminder = RecruiterReminder::query()->sole();

    expect($reminder)
        ->company_id->toBe($company->getKey())
        ->creator_id->toBe($owner->getKey())
        ->assignee_id->toBe($owner->getKey())
        ->job_posting_id->toBe($job->getKey())
        ->and(ActivityEntry::query()->where('event', 'reminder.created')->sole()->payload)
        ->toBe(['title' => 'Freitag zurückrufen']);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.reminders.store'), [
            'title' => 'Unzulässige Zuweisung',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toIso8601String(),
            'assignee_id' => $foreignOwner->getKey(),
        ])
        ->assertUnprocessable();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.reminders.store'), [
            'title' => 'Fremde Stelle',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toIso8601String(),
            'job_posting_id' => $foreignJob->getKey(),
        ])
        ->assertNotFound();

    expect(RecruiterReminder::query()->count())->toBe(1);
});

it('enforces reminder permissions and hides reminders from other tenants', function () {
    ['user' => $owner, 'company' => $company] = erinReminderEmployer();
    ['user' => $recruiter] = erinReminderEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinReminderEmployer();

    CompanyMembership::query()->where('user_id', $recruiter->getKey())->delete();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $recruiter->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => now(),
    ]);

    $reminder = RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $owner->getKey(),
        'title' => 'Interview vorbereiten',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($recruiter)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.reminders.update', $reminder), ['completed' => true])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.reminders.update', $reminder), ['completed' => true])
        ->assertRedirect();

    expect($reminder->fresh()?->completed_at)->not->toBeNull();

    $foreignReminder = RecruiterReminder::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'creator_id' => $foreignOwner->getKey(),
        'assignee_id' => $foreignOwner->getKey(),
        'title' => 'Fremde Erinnerung',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.reminders.destroy', $foreignReminder))
        ->assertNotFound();

    expect(RecruiterReminder::query()->find($foreignReminder->getKey()))->not->toBeNull();
});

it('keeps company viewers read only for recruiter reminders', function () {
    ['user' => $owner, 'company' => $company] = erinReminderEmployer();
    $viewer = User::factory()->create(['role' => UserRole::Company]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $viewer->getKey(),
        'role' => CompanyMemberRole::Viewer,
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.reminders.store'), [
            'title' => 'Viewer darf nicht zugewiesen werden',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toIso8601String(),
            'assignee_id' => $viewer->getKey(),
        ])
        ->assertUnprocessable();

    $reminder = RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $viewer->getKey(),
        'title' => 'Bestehende Erinnerung',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($viewer)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.reminders.update', $reminder), ['completed' => true])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.reminders.destroy', $reminder))
        ->assertForbidden();

    expect($reminder->fresh())
        ->completed_at->toBeNull()
        ->and(RecruiterReminder::query()->find($reminder->getKey()))->not->toBeNull();
});

it('sends each due reminder once and respects browser-push preferences', function () {
    Notification::fake();
    ['user' => $owner, 'company' => $company] = erinReminderEmployer();
    $owner->notificationPreferences()->create([
        'event' => 'reminder',
        'database_enabled' => false,
        'email_enabled' => false,
        'push_enabled' => true,
    ]);
    $owner->updatePushSubscription(
        'https://push.example.test/subscriptions/reminder-device',
        str_repeat('p', 87),
        str_repeat('a', 22),
        'aes128gcm',
    );
    $due = RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $owner->getKey(),
        'title' => 'Nach Dokument fragen',
        'priority' => 'high',
        'due_at' => now()->subMinute(),
    ]);
    RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $owner->getKey(),
        'title' => 'Noch nicht fällig',
        'priority' => 'normal',
        'due_at' => now()->addHour(),
    ]);
    RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $owner->getKey(),
        'title' => 'Schon erledigt',
        'priority' => 'normal',
        'due_at' => now()->subHour(),
        'completed_at' => now(),
    ]);

    $this->artisan(SendDueRecruiterReminders::class)
        ->expectsOutput('1 Erinnerung(en) versendet.')
        ->assertSuccessful();
    $this->artisan(SendDueRecruiterReminders::class)
        ->expectsOutput('0 Erinnerung(en) versendet.')
        ->assertSuccessful();

    Notification::assertSentToTimes($owner, ActivityNotification::class, 1);
    Notification::assertSentTo(
        $owner,
        ActivityNotification::class,
        function (ActivityNotification $notification) use ($owner, $due): bool {
            expect($notification->via($owner))->toBe([WebPushChannel::class])
                ->and($notification->toArray($owner))
                ->toMatchArray([
                    'event' => 'reminder.due',
                    'title' => 'Erinnerung ist fällig',
                    'message' => 'Nach Dokument fragen',
                    'reminder_id' => $due->getKey(),
                ])
                ->and($notification->toWebPush($owner)->toArray())
                ->toMatchArray([
                    'title' => 'Erinnerung ist fällig',
                    'body' => 'Nach Dokument fragen',
                    'tag' => 'erin-reminder',
                    'data' => [
                        'event' => 'reminder.due',
                        'url' => route('employer.productivity'),
                    ],
                ]);

            return true;
        },
    );

    expect($due->fresh()?->notified_at)->not->toBeNull();
});
