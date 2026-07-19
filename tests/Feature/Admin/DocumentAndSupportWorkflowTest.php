<?php

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows only superadmins to verify files that passed the malware scan', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $profile = CandidateProfile::factory()->create();
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->id,
        'type' => CandidateDocumentType::Cv,
        'title' => 'Lebenslauf',
        'path' => 'candidate-documents/cv.pdf',
        'original_name' => 'cv.pdf',
        'mime_type' => 'application/pdf',
        'status' => CandidateDocumentStatus::InReview,
    ]);

    $this->actingAs($support)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Verified->value,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Verified->value,
        ])
        ->assertSessionHasErrors('status');

    $document->update([
        'scan_completed_at' => now(),
        'scan_result' => 'clean',
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Verified->value,
        ])
        ->assertRedirect();

    expect($document->refresh()->status)->toBe(CandidateDocumentStatus::Verified)
        ->and($document->verified_by)->toBe($admin->id)
        ->and($document->verified_at)->not->toBeNull()
        ->and(AuditLog::query()
            ->where('event', 'admin.candidate_document.reviewed')
            ->where('auditable_id', $document->id)
            ->exists())->toBeTrue();
});

it('requires a reason when a document is rejected', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'type' => CandidateDocumentType::Qualification,
        'title' => 'Abschluss',
        'path' => 'candidate-documents/qualification.pdf',
        'original_name' => 'qualification.pdf',
        'status' => CandidateDocumentStatus::InReview,
        'scan_completed_at' => now(),
        'scan_result' => 'clean',
    ]);

    $this->actingAs($support)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Rejected->value,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Rejected->value,
        ])
        ->assertSessionHasErrors('rejection_reason');

    $this->actingAs($admin)
        ->patch(route('admin.documents.review', $document), [
            'status' => CandidateDocumentStatus::Rejected->value,
            'rejection_reason' => 'Die eingereichte Datei ist nicht vollständig lesbar.',
        ])
        ->assertRedirect();

    expect($document->refresh()->status)->toBe(CandidateDocumentStatus::Rejected)
        ->and($document->rejection_reason)->toContain('nicht vollständig lesbar');
});

it('supports assignment and replies without putting message content into the audit log', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->id,
        'number' => 'ERIN-1001',
        'subject' => 'Problem mit meinem Profil',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);

    $this->actingAs($support)
        ->patch(route('admin.support.update', $ticket), [
            'status' => SupportTicketStatus::InProgress->value,
            'priority' => 'high',
            'assigned_to' => $support->id,
        ])
        ->assertRedirect();

    $this->actingAs($support)
        ->post(route('admin.support.reply', $ticket), [
            'body' => 'Wir haben dein Anliegen geprüft und melden uns mit dem nächsten Schritt.',
            'is_internal' => false,
        ])
        ->assertRedirect();

    expect($ticket->refresh()->status)->toBe(SupportTicketStatus::WaitingForCustomer)
        ->and($ticket->assigned_to)->toBe($support->id)
        ->and($ticket->messages()->count())->toBe(1);

    $audit = AuditLog::query()->where('event', 'admin.support_ticket.replied')->firstOrFail();

    expect($audit->metadata)->toHaveKeys(['message_id', 'is_internal'])
        ->and(json_encode($audit->toArray()))->not->toContain('Wir haben dein Anliegen');
});
