<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivityEventBackfill
{
    public function run(?int $throughId = null): int
    {
        $updated = 0;
        $query = DB::table('activity_entries')->orderBy('id');

        if ($throughId !== null) {
            $query->where('id', '<=', $throughId);
        }

        $query->where(function ($query): void {
            $query->whereNull('event_uuid')
                ->orWhereNull('schema_version')
                ->orWhere('schema_version', '<', 1)
                ->orWhere('data_quality', '!=', 'backfilled');
        })->chunkById(500, function ($entries) use (&$updated): void {
            foreach ($entries as $entry) {
                DB::table('activity_entries')->where('id', $entry->id)->update([
                    'event_uuid' => $entry->event_uuid ?: (string) Str::uuid(),
                    'schema_version' => max(1, (int) ($entry->schema_version ?? 1)),
                    'data_quality' => 'backfilled',
                ]);
                $updated++;
            }
        });

        return $updated;
    }
}
