<?php

namespace App\Services\Ticketing;

final class ZammadEndpoint
{
    public static function configuredBaseUrl(): ?string
    {
        $url = config('services.zammad.url');

        return self::integrationBaseUrl(
            is_string($url) ? $url : null,
            (bool) config('services.zammad.allow_local_http', false),
            self::configuredLocalHttpHosts(),
            (string) config('app.env', 'production'),
        );
    }

    public static function configuredCallbackUrl(): ?string
    {
        $url = config('services.zammad.webhook_callback_url');

        return self::integrationCallbackUrl(
            is_string($url) ? $url : null,
            (bool) config('services.zammad.allow_local_http', false),
            self::configuredLocalHttpHosts(),
            (string) config('app.env', 'production'),
        );
    }

    public static function secureBaseUrl(?string $url): ?string
    {
        return self::validatedUrl($url, ['', '/'], false, [], 'production');
    }

    /**
     * @param  list<string>  $localHttpHosts
     */
    public static function integrationBaseUrl(
        ?string $url,
        bool $allowLocalHttp,
        array $localHttpHosts,
        string $environment,
    ): ?string {
        return self::validatedUrl(
            $url,
            ['', '/'],
            $allowLocalHttp,
            $localHttpHosts,
            $environment,
        );
    }

    /**
     * @param  list<string>  $localHttpHosts
     */
    public static function integrationCallbackUrl(
        ?string $url,
        bool $allowLocalHttp,
        array $localHttpHosts,
        string $environment,
    ): ?string {
        return self::validatedUrl(
            $url,
            ['/integrations/zammad/webhook'],
            $allowLocalHttp,
            $localHttpHosts,
            $environment,
        );
    }

    /**
     * @param  list<string>  $allowedPaths
     * @param  list<string>  $localHttpHosts
     */
    private static function validatedUrl(
        ?string $url,
        array $allowedPaths,
        bool $allowLocalHttp,
        array $localHttpHosts,
        string $environment,
    ): ?string {
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
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));

        if (
            $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, $allowedPaths, true)
        ) {
            return null;
        }

        if ($scheme === 'https') {
            if ($port !== null && $port !== 443) {
                return null;
            }

            return rtrim($url, '/');
        }

        $normalizedLocalHosts = array_values(array_unique(array_filter(array_map(
            static fn (string $allowedHost): string => mb_strtolower(rtrim(trim($allowedHost), '.')),
            $localHttpHosts,
        ))));

        if (
            $scheme !== 'http'
            || ! $allowLocalHttp
            || ! in_array($environment, ['local', 'testing'], true)
            || ! in_array($host, $normalizedLocalHosts, true)
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1
        ) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * @return list<string>
     */
    private static function configuredLocalHttpHosts(): array
    {
        return array_values(array_map(
            static fn (mixed $host): string => (string) $host,
            (array) config('services.zammad.local_http_hosts', []),
        ));
    }
}
