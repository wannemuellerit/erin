<?php

namespace Tests\Support;

use App\Contracts\ExternalMessageProvider;
use App\Data\ExternalMessageRequest;
use App\Data\ExternalMessageResult;
use Throwable;

final class ErinExternalMessageProvider implements ExternalMessageProvider
{
    /** @var list<ExternalMessageRequest> */
    public array $requests = [];

    public string $status = 'queued';

    public int $costMicros = 75000;

    public ?Throwable $exception = null;

    public function send(ExternalMessageRequest $request): ExternalMessageResult
    {
        $this->requests[] = $request;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return new ExternalMessageResult(
            provider: 'fake',
            messageId: 'fake-message-'.count($this->requests),
            status: $this->status,
            costMicros: $this->costMicros,
        );
    }
}
