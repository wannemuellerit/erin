<?php

namespace App\Services\Privacy;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GdprRequest;
use App\Models\MessageAttachment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GdprErasure
{
    /**
     * @return array<string, int|string>
     */
    public function erase(GdprRequest $request, User $user): array
    {
        if ($request->legal_hold) {
            throw new DomainException('Die Löschung ist durch einen aktiven Legal Hold gesperrt.');
        }

        if (in_array($user->role, [UserRole::SuperAdmin, UserRole::Support], true)) {
            throw new DomainException('Plattformrollen müssen vor einer Löschung kontrolliert übertragen werden.');
        }

        $profile = $user->candidateProfile()->withTrashed()->first();
        $deletedFiles = 0;

        if ($profile !== null) {
            $profile->unsearchable();
            foreach ($profile->documents()->withTrashed()->get() as $document) {
                if (Storage::disk($document->disk)->delete($document->path)) {
                    $deletedFiles++;
                }
                $document->forceDelete();
            }
        }

        $messageIds = DB::table('messages')
            ->where('sender_id', $user->getKey())
            ->pluck('id');
        foreach (MessageAttachment::query()->whereIn('message_id', $messageIds)->get() as $attachment) {
            if (Storage::disk($attachment->disk)->delete($attachment->path)) {
                $deletedFiles++;
            }
            $attachment->delete();
        }

        DB::transaction(function () use ($profile, $request, $user): void {
            DB::table('messages')->where('sender_id', $user->getKey())->update([
                'body' => '[DSGVO-pseudonymisiert]',
                'translations' => null,
                'metadata' => null,
                'edited_at' => now(),
            ]);
            DB::table('support_ticket_messages')->where('author_id', $user->getKey())->update([
                'body' => '[DSGVO-pseudonymisiert]',
                'attachments' => null,
                'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('requester_id', $user->getKey())->update([
                'subject' => '[DSGVO-pseudonymisiert]',
                'updated_at' => now(),
            ]);
            DB::table('feedbacks')->where('author_id', $user->getKey())->update([
                'comment' => null,
                'updated_at' => now(),
            ]);
            DB::table('referrals')->where('referred_user_id', $user->getKey())->update([
                'visitor_token' => null,
                'email_hash' => null,
                'metadata' => null,
                'updated_at' => now(),
            ]);
            DB::table('ai_runs')->where('user_id', $user->getKey())->delete();
            DB::table('ai_consents')->where('user_id', $user->getKey())->update([
                'ip_address' => null,
                'data_categories' => null,
                'withdrawn_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('login_histories')->where('user_id', $user->getKey())->update([
                'user_id' => null,
                'email' => 'pseudonymisiert',
                'ip_address' => null,
                'user_agent' => null,
                'failure_reason' => null,
            ]);
            DB::table('audit_logs')->where('actor_id', $user->getKey())->update([
                'actor_id' => null,
                'before_values' => null,
                'after_values' => null,
                'metadata' => json_encode([
                    'pseudonymized_for_gdpr_request' => $request->getKey(),
                ], JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'user_agent' => null,
            ]);
            DB::table('push_subscriptions')
                ->where('subscribable_type', $user->getMorphClass())
                ->where('subscribable_id', $user->getKey())
                ->delete();
            DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->getKey())
                ->delete();
            DB::table('notification_preferences')->where('user_id', $user->getKey())->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $user->passkeys()->delete();

            if ($profile !== null) {
                $profile->forceFill([
                    'first_name' => 'Gelöscht',
                    'last_name' => 'Person '.$user->getKey(),
                    'birth_date' => null,
                    'gender' => null,
                    'nationality_country_code' => null,
                    'current_country_code' => null,
                    'current_city' => null,
                    'phone' => null,
                    'whatsapp' => null,
                    'summary' => null,
                    'published_at' => null,
                ])->save();
            }

            $user->forceFill([
                'name' => 'Gelöschte Person',
                'email' => sprintf('deleted-%d-%s@erased.invalid', $user->getKey(), Str::lower(Str::random(12))),
                'password' => Str::random(64),
                'status' => UserStatus::Blocked,
                'email_verified_at' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'last_active_at' => null,
                'blocked_reason' => 'DSGVO-Löschung abgeschlossen',
            ])->save();
        }, 3);

        return [
            'subject_user_id' => $user->getKey(),
            'deleted_private_files' => $deletedFiles,
            'result' => 'pseudonymized',
        ];
    }
}
