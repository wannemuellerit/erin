<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Interview;
use App\Models\InterviewAttendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');
        abort_if($apiKey === '' || $apiSecret === '', 503);
        abort_unless($request->header('Content-Type') === 'application/webhook+json', 415);

        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('services.livekit.webhook_max_bytes', 262144), 413);
        $authorization = (string) $request->header('Authorization');
        abort_unless(str_starts_with($authorization, 'Bearer '), 401);
        try {
            $claims = JWT::decode(substr($authorization, 7), new Key($apiSecret, 'HS256'));
        } catch (Throwable) {
            abort(401);
        }
        $claimHash = isset($claims->sha256) && is_string($claims->sha256)
            ? base64_decode($claims->sha256, true)
            : false;
        abort_unless(
            ($claims->iss ?? null) === $apiKey
            && is_string($claimHash)
            && hash_equals(hash('sha256', $body, true), $claimHash),
            401,
        );

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422);
        }
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['event'] ?? '');
        $roomName = (string) ($payload['room']['name'] ?? '');
        $identity = (string) ($payload['participant']['identity'] ?? '');
        abort_if(
            $eventId === '' || mb_strlen($eventId) > 255
            || $eventType === '' || mb_strlen($eventType) > 255
            || $roomName === '' || mb_strlen($roomName) > 255,
            422,
        );

        $interview = Interview::query()
            ->with('application.jobPosting')
            ->where('livekit_room_name', $roomName)
            ->firstOrFail();
        $occurredAt = CarbonImmutable::createFromTimestamp(
            (int) ($payload['createdAt'] ?? time()),
            (string) config('app.timezone'),
        );

        $created = DB::table('integration_receipts')->insertOrIgnore([
            'provider' => 'livekit',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => 'processing',
            'payload_hash' => hash('sha256', $body),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($created === 0) {
            $storedHash = DB::table('integration_receipts')
                ->where('provider', 'livekit')
                ->where('event_id', $eventId)
                ->value('payload_hash');
            abort_unless(is_string($storedHash) && hash_equals($storedHash, hash('sha256', $body)), 409);

            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        try {
            DB::transaction(function () use ($interview, $eventType, $identity, $occurredAt, $eventId): void {
                if (in_array($eventType, ['participant_joined', 'participant_left'], true)) {
                    $user = $this->authorizedParticipant($interview, $identity);
                    $attendance = InterviewAttendance::query()->lockForUpdate()->firstOrCreate(
                        [
                            'interview_id' => $interview->getKey(),
                            'participant_identity' => $identity,
                        ],
                        ['user_id' => $user->getKey()],
                    );
                    if ($eventType === 'participant_joined') {
                        $attendance->update([
                            'first_joined_at' => $attendance->first_joined_at ?? $occurredAt,
                            'last_joined_at' => $occurredAt,
                            'join_count' => $attendance->join_count + 1,
                        ]);
                    } else {
                        $seconds = $attendance->last_joined_at === null
                            ? 0
                            : (int) max(0, $attendance->last_joined_at->diffInSeconds($occurredAt));
                        $attendance->update([
                            'last_left_at' => $occurredAt,
                            'total_seconds' => $attendance->total_seconds + $seconds,
                        ]);
                    }
                }

                if (
                    $eventType === 'room_finished'
                    && $interview->ends_at?->lte($occurredAt)
                    && $interview->status === InterviewStatus::Confirmed
                ) {
                    $interview->update([
                        'status' => $interview->attendances()->whereNotNull('first_joined_at')->count() >= 2
                            ? InterviewStatus::Completed
                            : InterviewStatus::NoShow,
                    ]);
                }

                DB::table('integration_receipts')
                    ->where('provider', 'livekit')
                    ->where('event_id', $eventId)
                    ->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            DB::table('integration_receipts')
                ->where('provider', 'livekit')
                ->where('event_id', $eventId)
                ->delete();
            throw $exception;
        }

        return response()->json(['accepted' => true], 202);
    }

    private function authorizedParticipant(Interview $interview, string $identity): User
    {
        abort_unless(preg_match('/\Aerin-user-(\d+)\z/', $identity, $matches) === 1, 422);
        $user = User::query()->findOrFail((int) $matches[1]);
        $application = $interview->application;
        $isCandidate = $application->candidateProfile->user_id === $user->getKey();
        $isCompany = $user->hasCompanyRole($application->jobPosting->company_id, [
            CompanyMemberRole::Owner,
            CompanyMemberRole::Admin,
            CompanyMemberRole::Recruiter,
        ]);
        abort_unless($isCandidate || $isCompany, 403);

        return $user;
    }
}
