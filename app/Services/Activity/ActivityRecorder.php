<?php

namespace App\Services\Activity;

use App\Events\ActivityEntryCreated;
use App\Models\ActivityEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ActivityRecorder
{
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
    ): ActivityEntry {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        /** @var ActivityEntry $entry */
        $entry = ActivityEntry::query()->create([
            'company_id' => $companyId,
            'actor_id' => $actor?->getKey(),
            'subject_user_id' => $subjectUser?->getKey(),
            'event' => $event,
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
}
