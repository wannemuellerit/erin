<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdCampaignController extends Controller
{
    public function impression(Request $request, AdCampaign $campaign): Response
    {
        return $this->record($request, $campaign, 'impression');
    }

    public function click(Request $request, AdCampaign $campaign): Response
    {
        return $this->record($request, $campaign, 'click');
    }

    public function media(Request $request, AdCampaign $campaign): StreamedResponse|BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_if($campaign->media_path === null || $campaign->media_disk === null, 404);

        return Storage::disk($campaign->media_disk)->download(
            $campaign->media_path,
            basename($campaign->media_path),
            ['Content-Type' => $campaign->media_mime ?: 'application/octet-stream'],
        );
    }

    private function record(
        Request $request,
        AdCampaign $campaign,
        string $type,
    ): Response {
        $role = $request->user()?->role->value;
        $audience = $role === 'candidate' ? 'candidate' : ($role === 'company' ? 'company' : null);
        abort_unless(
            $campaign->enabled
            && $audience !== null
            && ($campaign->audience === 'all' || $campaign->audience === $audience)
            && ($campaign->starts_at === null || $campaign->starts_at->isPast())
            && ($campaign->ends_at === null || $campaign->ends_at->isFuture()),
            404,
        );
        DB::transaction(function () use ($request, $campaign, $type): void {
            $event = AdEvent::query()->firstOrCreate([
                'ad_campaign_id' => $campaign->getKey(),
                'user_id' => $request->user()->getKey(),
                'type' => $type,
                'event_date' => today()->toDateString(),
            ]);
            if (! $event->wasRecentlyCreated) {
                $event->increment('occurrences');
            }
        });

        return response()->noContent();
    }
}
