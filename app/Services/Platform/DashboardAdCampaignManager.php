<?php

namespace App\Services\Platform;

use App\Models\AdCampaign;
use App\Models\User;
use Illuminate\Support\Str;

class DashboardAdCampaignManager
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sync(array $data, ?User $actor): AdCampaign
    {
        $campaign = isset($data['campaign_id'])
            ? AdCampaign::query()->whereKey($data['campaign_id'])->first()
            : null;
        $campaign ??= new AdCampaign([
            'public_id' => (string) Str::uuid(),
            'created_by' => $actor?->getKey(),
        ]);
        $campaign->fill([
            'name' => (string) ($data['campaign_name'] ?? $data['title_de'] ?? 'Dashboard-Anzeige'),
            'placement' => 'dashboard',
            'audience' => $data['audience'],
            'content' => [
                'title_de' => $data['title_de'],
                'title_en' => $data['title_en'],
                'body_de' => $data['body_de'],
                'body_en' => $data['body_en'],
                'cta_label_de' => $data['cta_label_de'],
                'cta_label_en' => $data['cta_label_en'],
            ],
            'target_url' => $data['url'] ?: null,
            'enabled' => $data['enabled'],
            'starts_at' => $data['starts_at'] ?: null,
            'ends_at' => $data['ends_at'] ?: null,
            'updated_by' => $actor?->getKey(),
        ])->save();

        return $campaign;
    }

    /**
     * @return array{impressions: int, clicks: int, ctr: float}
     */
    public function statistics(?int $campaignId): array
    {
        $campaign = $campaignId
            ? AdCampaign::query()->whereKey($campaignId)->first()
            : null;
        $impressions = (int) ($campaign?->events()->where('type', 'impression')->sum('occurrences') ?? 0);
        $clicks = (int) ($campaign?->events()->where('type', 'click')->sum('occurrences') ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
        ];
    }
}
