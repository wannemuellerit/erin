<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Data\VideoAccess;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use RuntimeException;

final class LiveKitVideoProvider implements VideoProvider
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function issueAccess(
        string $roomName,
        string $participantIdentity,
        string $participantName,
        array $metadata = [],
    ): VideoAccess {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');
        $url = (string) config('services.livekit.url');

        if ($apiKey === '' || $apiSecret === '' || $url === '') {
            throw new RuntimeException('LiveKit ist nicht vollständig konfiguriert.');
        }

        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes(
            (int) config('services.livekit.token_ttl_minutes', 120),
        );
        $e2eeKey = (string) ($metadata['e2ee_key'] ?? Str::password(48, symbols: false));
        unset($metadata['e2ee_key']);

        $token = JWT::encode([
            'iss' => $apiKey,
            'sub' => $participantIdentity,
            'name' => $participantName,
            'nbf' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'video' => [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ], $apiSecret, 'HS256');

        return new VideoAccess(
            url: $url,
            roomName: $roomName,
            participantIdentity: $participantIdentity,
            token: $token,
            e2eeKey: $e2eeKey,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }
}
