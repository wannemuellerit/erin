<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class TrustedPushEndpoint implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__('Der Browser-Push-Endpunkt muss HTTPS verwenden und zu einem freigegebenen Push-Dienst gehören.'));

            return;
        }

        $parts = parse_url($value);
        $host = is_array($parts) && is_string($parts['host'] ?? null)
            ? mb_strtolower(rtrim($parts['host'], '.'))
            : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $hasCredentials = is_array($parts)
            && (isset($parts['user']) || isset($parts['pass']));
        $hasFragment = is_array($parts) && isset($parts['fragment']);
        $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $configuredHosts = config('webpush.allowed_endpoint_hosts', []);
        $allowedHosts = [];
        if (is_array($configuredHosts)) {
            foreach ($configuredHosts as $allowedHost) {
                if (! is_string($allowedHost)) {
                    continue;
                }

                $allowedHost = mb_strtolower(trim($allowedHost, " \t\n\r\0\x0B."));
                if ($allowedHost !== '') {
                    $allowedHosts[] = $allowedHost;
                }
            }
        }
        $isAllowedHost = false;
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                $isAllowedHost = true;

                break;
            }
        }

        if (
            $scheme !== 'https'
            || $host === ''
            || ($port !== null && $port !== 443)
            || $hasCredentials
            || $hasFragment
            || $isIpAddress
            || ! $isAllowedHost
        ) {
            $fail(__('Der Browser-Push-Endpunkt muss HTTPS verwenden und zu einem freigegebenen Push-Dienst gehören.'));
        }
    }
}
