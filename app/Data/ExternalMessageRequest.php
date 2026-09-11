<?php

namespace App\Data;

final readonly class ExternalMessageRequest
{
    public function __construct(
        public string $channel,
        public string $to,
        public string $body,
        public string $idempotencyKey,
        public string $locale,
        public ?string $templateKey = null,
    ) {}
}
