<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedactHorizonPayloads
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $response->setData($this->redact($response->getData(true)));

        return $response;
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key === 'exception' && is_string($value) && $value !== '') {
            return '[exception details redacted]';
        }

        if ($key === 'payload') {
            return $this->redactPayload($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->redact($childValue, is_string($childKey) ? $childKey : null);
        }

        return $value;
    }

    private function redactPayload(mixed $payload): mixed
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                return '[job payload redacted]';
            }

            return json_encode($this->redactPayload($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (! is_array($payload)) {
            return $payload;
        }

        $commandName = is_array($payload['data'] ?? null)
            ? ($payload['data']['commandName'] ?? null)
            : null;
        $payload['data'] = array_filter([
            'commandName' => is_string($commandName) ? $commandName : null,
            'redacted' => true,
        ], static fn (mixed $value): bool => $value !== null);

        return $payload;
    }
}
