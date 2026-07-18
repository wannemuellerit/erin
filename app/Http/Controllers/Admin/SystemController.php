<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GdprRequestStatus;
use App\Models\AccessListEntry;
use App\Models\EmailTemplate;
use App\Models\FeatureFlag;
use App\Models\GdprRequest;
use App\Models\IntegrationReceipt;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends AdminController
{
    public function index(): Response
    {
        return Inertia::render('admin/System', [
            'feature_flags' => FeatureFlag::query()
                ->with('updater:id,name,email')
                ->orderBy('key')
                ->get(),
            'login_history' => LoginHistory::query()
                ->with('user:id,name,email')
                ->latest('created_at')
                ->limit(30)
                ->get(),
            'gdpr_requests' => GdprRequest::query()
                ->select([
                    'id',
                    'user_id',
                    'handled_by',
                    'verified_by',
                    'approved_by',
                    'type',
                    'status',
                    'reason',
                    'legal_hold',
                    'legal_hold_reason',
                    'processing_started_at',
                    'failed_at',
                    'failure_reason',
                    'export_expires_at',
                    'downloaded_at',
                    'result_summary',
                    'verified_at',
                    'due_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'user:id,name,email,role,status',
                    'handler:id,name,email',
                    'verifier:id,name,email',
                    'approver:id,name,email',
                ])
                ->orderByRaw(
                    "case status when 'requested' then 0 when 'verified' then 1 when 'processing' then 2 else 3 end",
                )
                ->latest('created_at')
                ->paginate(10, pageName: 'gdpr_page')
                ->withQueryString()
                ->through(function (GdprRequest $request): array {
                    $data = $request->toArray();
                    $data['download_url'] = $request->type === 'export'
                        && $request->status === GdprRequestStatus::Completed
                        && $request->downloaded_at === null
                        && ($request->export_expires_at?->isFuture() ?? false)
                            ? URL::temporarySignedRoute(
                                'admin.gdpr-requests.download',
                                now()->addMinutes(10),
                                ['gdprRequest' => $request],
                            )
                            : null;

                    return $data;
                }),
            'gdpr_statuses' => array_map(
                static fn (GdprRequestStatus $status): string => $status->value,
                GdprRequestStatus::cases(),
            ),
            'gdpr_types' => ['export', 'delete'],
            'access_list_entries' => AccessListEntry::query()
                ->with('creator:id,name,email')
                ->latest()
                ->limit(100)
                ->get(),
            'email_templates' => EmailTemplate::query()
                ->with('updater:id,name,email')
                ->whereIn('locale', ['de', 'en'])
                ->orderBy('key')
                ->orderBy('locale')
                ->limit(100)
                ->get(),
            'gdpr' => [
                'open' => GdprRequest::query()
                    ->whereNotIn('status', ['completed', 'rejected'])
                    ->count(),
                'overdue' => GdprRequest::query()
                    ->whereDate('due_at', '<', today())
                    ->whereNotIn('status', ['completed', 'rejected'])
                    ->count(),
            ],
            'governance' => [
                'access_list_entries' => AccessListEntry::query()->count(),
                'email_templates' => EmailTemplate::query()->count(),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'queue_connection' => config('queue.default'),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'integrations' => [
                'stripe' => filled(config('cashier.secret') ?? config('services.stripe.secret')),
                'openai' => filled(config('services.openai.api_key')),
                'livekit' => filled(config('services.livekit.key'))
                    && filled(config('services.livekit.secret')),
                'recent_failed_webhooks' => IntegrationReceipt::query()
                    ->where('status', 'failed')
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
        ]);
    }
}
