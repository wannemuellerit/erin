<?php

namespace App\Services\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class GdprExportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $profileId = $user->candidateProfile?->getKey();
        $applicationIds = $profileId === null
            ? collect()
            : DB::table('applications')
                ->where('candidate_profile_id', $profileId)
                ->pluck('id');
        $ticketIds = DB::table('support_tickets')
            ->where('requester_id', $user->getKey())
            ->pluck('id');
        $conversationIds = DB::table('conversation_participants')
            ->where('user_id', $user->getKey())
            ->pluck('conversation_id');

        return [
            'format' => 'erin-gdpr-export-v1',
            'generated_at' => now()->toIso8601String(),
            'subject' => [
                'id' => $user->getKey(),
                'account' => $user->makeVisible(['created_at', 'updated_at'])->toArray(),
            ],
            'candidate' => $profileId === null ? null : [
                'profile' => DB::table('candidate_profiles')->where('id', $profileId)->first(),
                'experiences' => DB::table('candidate_experiences')->where('candidate_profile_id', $profileId)->get(),
                'educations' => DB::table('candidate_educations')->where('candidate_profile_id', $profileId)->get(),
                'skills' => DB::table('candidate_skill')->where('candidate_profile_id', $profileId)->get(),
                'languages' => DB::table('candidate_language')->where('candidate_profile_id', $profileId)->get(),
                'documents_metadata' => DB::table('candidate_documents')
                    ->where('candidate_profile_id', $profileId)
                    ->select([
                        'id',
                        'type',
                        'status',
                        'original_name',
                        'mime_type',
                        'size_bytes',
                        'sha256',
                        'scan_result',
                        'expires_at',
                        'shared_with_employers',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ])->get(),
                'applications' => DB::table('applications')->whereIn('id', $applicationIds)->get(),
                'application_status_history' => DB::table('application_status_histories')
                    ->whereIn('application_id', $applicationIds)->get(),
                'screening_answers' => DB::table('application_screening_answers')
                    ->whereIn('application_id', $applicationIds)->get(),
            ],
            'communications' => [
                'conversation_participation' => DB::table('conversation_participants')
                    ->where('user_id', $user->getKey())->get(),
                'sent_messages' => DB::table('messages')
                    ->where('sender_id', $user->getKey())
                    ->whereIn('conversation_id', $conversationIds)
                    ->get(),
                'support_tickets' => DB::table('support_tickets')->whereIn('id', $ticketIds)->get(),
                'support_messages' => DB::table('support_ticket_messages')->whereIn('support_ticket_id', $ticketIds)->get(),
            ],
            'privacy_and_ai' => [
                'consents' => DB::table('ai_consents')->where('user_id', $user->getKey())->get(),
                'ai_runs' => DB::table('ai_runs')->where('user_id', $user->getKey())->get(),
                'notification_preferences' => DB::table('notification_preferences')->where('user_id', $user->getKey())->get(),
                'notifications' => DB::table('notifications')
                    ->where('notifiable_type', $user->getMorphClass())
                    ->where('notifiable_id', $user->getKey())->get(),
                'push_devices' => DB::table('push_subscriptions')
                    ->where('subscribable_type', $user->getMorphClass())
                    ->where('subscribable_id', $user->getKey())
                    ->select(['id', 'content_encoding', 'created_at', 'updated_at'])
                    ->get(),
            ],
            'history' => [
                'login_history' => DB::table('login_histories')->where('user_id', $user->getKey())->get(),
                'activity' => DB::table('activity_entries')->where('subject_user_id', $user->getKey())->get(),
                'audit_events' => DB::table('audit_logs')->where('actor_id', $user->getKey())->get(),
            ],
            'referrals' => [
                'codes' => DB::table('referral_codes')->where('user_id', $user->getKey())->get(),
                'attributions' => DB::table('referrals')->where('referred_user_id', $user->getKey())->get(),
            ],
            'summary' => [
                'language' => $user->locale,
                'notice' => __('Dieses Paket enthält die in Erin gespeicherten personenbezogenen Daten und technischen Historien. Binäre Dokumentinhalte werden aus Sicherheitsgründen getrennt verwaltet.'),
            ],
        ];
    }
}
