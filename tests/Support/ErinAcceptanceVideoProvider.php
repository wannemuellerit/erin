<?php

namespace Tests\Support;

use App\Contracts\VideoProvider;
use App\Data\VideoAccess;

final class ErinAcceptanceVideoProvider implements VideoProvider
{
    /**
     * @var list<array{
     *     room_name: string,
     *     participant_identity: string,
     *     participant_name: string,
     *     metadata: array<string, mixed>
     * }>
     */
    public array $calls = [];

    public function issueAccess(
        string $roomName,
        string $participantIdentity,
        string $participantName,
        array $metadata = [],
    ): VideoAccess {
        $this->calls[] = [
            'room_name' => $roomName,
            'participant_identity' => $participantIdentity,
            'participant_name' => $participantName,
            'metadata' => $metadata,
        ];

        return new VideoAccess(
            url: 'wss://video.example.test',
            roomName: $roomName,
            participantIdentity: $participantIdentity,
            token: 'signed-fake-video-token',
            e2eeKey: (string) ($metadata['e2ee_key'] ?? ''),
            expiresAt: now()->addMinutes(20)->toIso8601String(),
        );
    }
}
