<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailSuppressionService
{
    public function isSuppressed(string $email): bool
    {
        // Mail routing can run during a rolling deploy before the new table is
        // visible on every instance; fail open only for this deploy window.
        if (! Schema::hasTable('email_suppressions')) {
            return false;
        }

        return DB::table('email_suppressions')
            ->where('email_hash', $this->hash($email))
            ->whereNull('released_at')
            ->exists();
    }

    public function suppress(string $email, string $reason, string $provider): void
    {
        $hash = $this->hash($email);
        $existing = DB::table('email_suppressions')->where('email_hash', $hash)->first();
        if ($existing === null) {
            DB::table('email_suppressions')->insert([
                'email_hash' => $hash,
                'reason' => $reason,
                'provider' => $provider,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }
        DB::table('email_suppressions')->where('email_hash', $hash)->update([
            'reason' => $reason,
            'provider' => $provider,
            'last_seen_at' => now(),
            'released_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
