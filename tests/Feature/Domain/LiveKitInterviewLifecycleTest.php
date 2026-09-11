<?php

use App\Contracts\VideoProvider;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\IntegrationReceipt;
use App\Models\Interview;
use App\Models\InterviewAttendance;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Video\LiveKitVideoProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ErinAcceptanceVideoProvider;

uses(RefreshDatabase::class);

it('issues short-lived room tokens with media and encrypted chat permissions', function () {
    config()->set('services.livekit.url', 'ws://localhost:7880');
    config()->set('services.livekit.api_key', 'erin-local-livekit-key');
    config()->set('services.livekit.api_secret', 'erin-local-livekit-secret-change-me');
    config()->set('services.livekit.token_ttl_minutes', 10);

    $access = (new LiveKitVideoProvider)->issueAccess(
        'erin-token-room',
        'erin-user-42',
        'Faden Testperson',
        ['e2ee_key' => 'shared-e2ee-key', 'interview_id' => 42],
    );
    $claims = (array) JWT::decode(
        $access->token,
        new Key('erin-local-livekit-secret-change-me', 'HS256'),
    );
    $video = (array) $claims['video'];

    expect($access->url)->toBe('ws://localhost:7880')
        ->and($access->participantName)->toBe('Faden Testperson')
        ->and($access->e2eeKey)->toBe('shared-e2ee-key')
        ->and($claims['sub'])->toBe('erin-user-42')
        ->and($video['room'])->toBe('erin-token-room')
        ->and($video['roomJoin'])->toBeTrue()
        ->and($video['canPublish'])->toBeTrue()
        ->and($video['canSubscribe'])->toBeTrue()
        ->and($video['canPublishData'])->toBeTrue()
        ->and($claims['exp'] - $claims['nbf'])->toBe(600);
});

/** @return array{0: User, 1: User, 2: Company, 3: Interview} */
function erinLiveKitInterview(): array
{
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $profile = CandidateProfile::factory()->create();
    $application = JobApplication::factory()->create([
        'job_posting_id' => JobPosting::factory()->create([
            'company_id' => $company->getKey(),
            'created_by' => $owner->getKey(),
        ])->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => ApplicationStatus::InterviewScheduled,
    ]);
    $interview = Interview::query()->create([
        'application_id' => $application->getKey(),
        'organizer_id' => $owner->getKey(),
        'proposed_by' => $owner->getKey(),
        'status' => InterviewStatus::Confirmed,
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(30),
        'timezone' => 'Europe/Berlin',
        'livekit_room_name' => 'erin-lifecycle-test-room',
        'confirmed_at' => now()->subHour(),
    ]);

    return [$owner, $profile->user, $company, $interview];
}

function erinSignedLiveKitWebhook(array $payload): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $token = JWT::encode([
        'iss' => config('services.livekit.api_key'),
        'nbf' => now()->subMinute()->timestamp,
        'exp' => now()->addMinute()->timestamp,
        'sha256' => base64_encode(hash('sha256', $body, true)),
    ], (string) config('services.livekit.api_secret'), 'HS256');

    return [$body, $token];
}

it('requires an entitled participant the time window and production-safe e2ee configuration', function () {
    [$owner, $candidate, $company, $interview] = erinLiveKitInterview();
    $viewer = User::factory()->create(['role' => UserRole::Company]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $viewer->getKey(),
        'role' => CompanyMemberRole::Viewer,
        'accepted_at' => now(),
    ]);
    $video = new ErinAcceptanceVideoProvider;
    app()->instance(VideoProvider::class, $video);

    $this->actingAs($viewer)->postJson(route('interviews.token', $interview))->assertForbidden();
    $this->actingAs($candidate)->postJson(route('interviews.token', $interview))
        ->assertOk()
        ->assertJsonPath('participantIdentity', 'erin-user-'.$candidate->getKey())
        ->assertJsonPath('roomName', 'erin-lifecycle-test-room')
        ->assertJsonPath('e2eeKey', fn ($key): bool => is_string($key) && $key !== '');

    config()->set('services.livekit.region', 'us');
    $this->actingAs($owner)->postJson(route('interviews.token', $interview))->assertStatus(503);
    expect($video->calls)->toHaveCount(1);
});

it('allows only an explicitly enabled localhost websocket outside production', function () {
    [$owner, , , $interview] = erinLiveKitInterview();
    $video = new ErinAcceptanceVideoProvider;
    app()->instance(VideoProvider::class, $video);

    config()->set('services.livekit.url', 'ws://localhost:7880');
    config()->set('services.livekit.allow_insecure_local', true);
    $this->actingAs($owner)->postJson(route('interviews.token', $interview))->assertOk();

    config()->set('services.livekit.url', 'ws://livekit.internal:7880');
    $this->actingAs($owner)->postJson(route('interviews.token', $interview))->assertStatus(503);

    config()->set('services.livekit.url', 'ws://127.0.0.1:7880');
    config()->set('services.livekit.allow_insecure_local', false);
    $this->actingAs($owner)->postJson(route('interviews.token', $interview))->assertStatus(503);
});

it('verifies livekit signatures and stores duplicate join and leave webhooks exactly once', function () {
    [, $candidate, , $interview] = erinLiveKitInterview();
    $joinedAt = now()->timestamp;
    [$joinBody, $joinToken] = erinSignedLiveKitWebhook([
        'id' => 'EV_join_once',
        'event' => 'participant_joined',
        'createdAt' => $joinedAt,
        'room' => ['name' => $interview->livekit_room_name],
        'participant' => ['identity' => 'erin-user-'.$candidate->getKey()],
    ]);

    $send = fn (string $body, string $token) => $this->call(
        'POST',
        route('integrations.livekit.webhook'),
        [],
        [],
        [],
        [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/webhook+json',
        ],
        $body,
    );
    $send($joinBody, $joinToken)->assertAccepted();
    $send($joinBody, $joinToken)->assertOk()->assertJsonPath('duplicate', true);

    [$leaveBody, $leaveToken] = erinSignedLiveKitWebhook([
        'id' => 'EV_leave_once',
        'event' => 'participant_left',
        'createdAt' => $joinedAt + 90,
        'room' => ['name' => $interview->livekit_room_name],
        'participant' => ['identity' => 'erin-user-'.$candidate->getKey()],
    ]);
    $send($leaveBody, $leaveToken)->assertAccepted();

    $attendance = InterviewAttendance::query()->sole();
    expect($attendance->join_count)->toBe(1)
        ->and($attendance->total_seconds)->toBe(90)
        ->and(IntegrationReceipt::query()->where('provider', 'livekit')->count())->toBe(2);

    $this->call('POST', route('integrations.livekit.webhook'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$joinToken,
        'CONTENT_TYPE' => 'application/webhook+json',
    ], str_replace('participant_joined', 'participant_left', $joinBody))->assertUnauthorized();
});

it('rejects oversized, malformed and conflicting LiveKit callbacks', function () {
    [, $candidate, , $interview] = erinLiveKitInterview();
    $send = fn (string $body, string $token) => $this->call(
        'POST',
        route('integrations.livekit.webhook'),
        [],
        [],
        [],
        ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/webhook+json'],
        $body,
    );
    $payload = [
        'id' => 'EV_conflict',
        'event' => 'participant_joined',
        'createdAt' => now()->timestamp,
        'room' => ['name' => $interview->livekit_room_name],
        'participant' => ['identity' => 'erin-user-'.$candidate->getKey()],
    ];
    [$body, $token] = erinSignedLiveKitWebhook($payload);

    config()->set('services.livekit.webhook_max_bytes', 8);
    $send($body, $token)->assertStatus(413);

    config()->set('services.livekit.webhook_max_bytes', 4096);
    $malformed = '{';
    $malformedToken = JWT::encode([
        'iss' => config('services.livekit.api_key'),
        'exp' => now()->addMinute()->timestamp,
        'sha256' => base64_encode(hash('sha256', $malformed, true)),
    ], (string) config('services.livekit.api_secret'), 'HS256');
    $send($malformed, $malformedToken)->assertUnprocessable();

    $send($body, $token)->assertAccepted();
    [$conflictBody, $conflictToken] = erinSignedLiveKitWebhook([...$payload, 'event' => 'participant_left']);
    $send($conflictBody, $conflictToken)->assertConflict();

    expect(InterviewAttendance::query()->sole()->join_count)->toBe(1)
        ->and(IntegrationReceipt::query()->where('provider', 'livekit')->count())->toBe(1);
});

it('marks a finished room as no show only after the scheduled end and enables feedback eligibility', function () {
    [, $candidate, , $interview] = erinLiveKitInterview();
    $interview->update(['ends_at' => now()->subMinute()]);
    InterviewAttendance::query()->create([
        'interview_id' => $interview->getKey(),
        'user_id' => $candidate->getKey(),
        'participant_identity' => 'erin-user-'.$candidate->getKey(),
        'first_joined_at' => now()->subMinutes(20),
        'last_joined_at' => now()->subMinutes(20),
        'last_left_at' => now()->subMinutes(2),
        'total_seconds' => 1080,
        'join_count' => 1,
    ]);
    [$body, $token] = erinSignedLiveKitWebhook([
        'id' => 'EV_room_finished_no_show',
        'event' => 'room_finished',
        'createdAt' => now()->timestamp,
        'room' => ['name' => $interview->livekit_room_name],
    ]);

    $this->call('POST', route('integrations.livekit.webhook'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/webhook+json',
    ], $body)->assertAccepted();

    expect($interview->refresh()->status)->toBe(InterviewStatus::NoShow);
});

it('enforces both participants weekly availability for proposals', function () {
    [$owner, $candidate, , $interview] = erinLiveKitInterview();
    $application = $interview->application;
    $start = Carbon::now('Europe/Berlin')->addWeek()->startOfWeek()->setTime(10, 0);
    foreach ([$owner, $candidate] as $participant) {
        $participant->availabilitySlots()->create([
            'weekday' => $start->dayOfWeekIso,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
            'timezone' => 'Europe/Berlin',
        ]);
    }

    $this->actingAs($owner)->post(route('interviews.propose', $application), [
        'slots' => [[
            'starts_at' => $start->copy()->setTime(13, 0)->toIso8601String(),
            'ends_at' => $start->copy()->setTime(14, 0)->toIso8601String(),
            'timezone' => 'Europe/Berlin',
        ]],
    ])->assertStatus(422);

    $this->actingAs($owner)->post(route('interviews.propose', $application), [
        'slots' => [[
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'timezone' => 'Europe/Berlin',
        ]],
    ])->assertRedirect(route('interviews.index'));
});

it('sends each participant idempotent 24-hour and one-hour reminders', function () {
    Notification::fake();
    [, , , $interview] = erinLiveKitInterview();
    $interview->update(['starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(3)]);

    $this->artisan('erin:interviews:send-reminders')->assertSuccessful();
    $this->artisan('erin:interviews:send-reminders')->assertSuccessful();
    expect(NotificationDelivery::query()->where('event', 'interview.reminder')->count())->toBe(2);

    $this->travel(70)->minutes();
    $this->artisan('erin:interviews:send-reminders')->assertSuccessful();
    $this->artisan('erin:interviews:send-reminders')->assertSuccessful();
    expect(NotificationDelivery::query()->where('event', 'interview.reminder')->count())->toBe(4);
});
