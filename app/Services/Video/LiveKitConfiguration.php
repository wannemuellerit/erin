<?php

namespace App\Services\Video;

final class LiveKitConfiguration
{
    public function isJoinReady(): bool
    {
        return filled(config('services.livekit.api_key'))
            && filled(config('services.livekit.api_secret'))
            && config('services.livekit.e2ee_required') === true
            && config('services.livekit.region') === 'eu'
            && (int) config('services.livekit.token_ttl_minutes') >= 1
            && (int) config('services.livekit.token_ttl_minutes') <= 10
            && $this->clientUrlIsAllowed();
    }

    public function isProductionReady(): bool
    {
        return $this->isJoinReady()
            && str_starts_with((string) config('services.livekit.url'), 'wss://');
    }

    private function clientUrlIsAllowed(): bool
    {
        $url = (string) config('services.livekit.url');

        if (str_starts_with($url, 'wss://')) {
            return true;
        }

        if (
            config('services.livekit.allow_insecure_local') !== true
            || ! app()->environment(['local', 'testing'])
        ) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'ws'
            && is_string($host)
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
