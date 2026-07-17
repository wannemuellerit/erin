<?php

namespace App\Data;

final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $schema
     * @param  list<array{mime_type: string, data: string, filename?: string}>  $documents
     */
    public function __construct(
        public string $task,
        public string $instructions,
        public array $input,
        public array $schema,
        public ?string $model = null,
        public array $documents = [],
        public bool $sensitive = false,
    ) {}
}
