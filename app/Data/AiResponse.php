<?php

namespace App\Data;

final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $providerId,
        public string $model,
        public array $result,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
