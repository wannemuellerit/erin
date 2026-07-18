<?php

namespace App\Services\Ticketing;

final class ZammadEndpoint
{
    public static function secureBaseUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = is_string($parts['host'] ?? null)
            ? mb_strtolower(rtrim($parts['host'], '.'))
            : '';
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';

        if (
            ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || ($port !== null && $port !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, ['', '/'], true)
        ) {
            return null;
        }

        return rtrim($url, '/');
    }
}
