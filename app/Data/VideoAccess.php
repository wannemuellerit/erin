<?php

namespace App\Data;

final readonly class VideoAccess
{
    public function __construct(
        public string $url,
        public string $roomName,
        public string $participantIdentity,
        public string $token,
        public string $e2eeKey,
        public string $expiresAt,
    ) {}
}
