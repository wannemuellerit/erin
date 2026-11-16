<?php

namespace App\Services\Activity;

use App\Events\ActivityEntryCreated;
use App\Models\ActivityEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActivityRecorder
{
    /** @var list<string> */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'email', 'phone', 'address', 'document', 'document_name', 'original_name',
        'message_body', 'content', 'token', 'secret', 'password', 'identity',
    ];

    /**
     * Store a presentation-safe product activity. Callers must only provide
     * non-sensitive labels and identifiers in the payload.
     *
     * @param  array<string, bool|float|int|string|null>  $payload
     */
    public function record(
        string $event,
        ?User $actor = null,
        Company|int|null $company = null,
        ?Model $subject = null,
        array $payload = [],
        ?User $subjectUser = null,
        string $visibility = 'company',
        ?string $idempotencyKey = null,
        int $schemaVersion = 1,
    ): ActivityEntry {
        $companyId = $company instanceof Company ? $company->getKey() : $company;
        $this->assertSafePayload($payload);
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Activity schema versions start at 1.');
        }

        $hashedKey = $idempotencyKey === null ? null : hash(
            'sha256',
            implode(':', [(string) ($companyId ?? 'platform'), $event, $idempotencyKey]),
        );

        if ($hashedKey !== null) {
            $existing = ActivityEntry::query()->where('idempotency_key', $hashedKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var ActivityEntry $entry */
        $entry = ActivityEntry::query()->create([
            'company_id' => $companyId,
            'actor_id' => $actor?->getKey(),
            'subject_user_id' => $subjectUser?->getKey(),
            'event' => $event,
            'schema_version' => $schemaVersion,
            'idempotency_key' => $hashedKey,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'visibility' => $visibility,
            'payload' => $payload ?: null,
            'occurred_at' => now(),
        ]);

        DB::afterCommit(static function () use ($entry): void {
            ActivityEntryCreated::dispatch($entry);
        });

        return $entry;
    }

    /** @param  array<string, bool|float|int|string|null>  $payload */
    private function assertSafePayload(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            if (in_array(strtolower($key), self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                throw new InvalidArgumentException("Private activity field: {$key}");
            }
        }
    }
}
