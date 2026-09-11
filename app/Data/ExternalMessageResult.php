<?php

namespace App\Data;

final readonly class ExternalMessageResult
{
    public function __construct(
        public string $provider,
        public string $messageId,
        public string $status,
        public int $costMicros = 0,
        public string $currency = 'EUR',
    ) {}
}
