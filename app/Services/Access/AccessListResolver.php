<?php

namespace App\Services\Access;

use App\Models\AccessListEntry;
use Illuminate\Support\Collection;

class AccessListResolver
{
    public function decide(?string $email, ?string $ip): AccessDecision
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedIp = $this->normalizeIp($ip);
        $domain = $this->emailDomain($normalizedEmail);

        /** @var Collection<int, AccessListEntry> $entries */
        $entries = AccessListEntry::query()
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->get();

        return AccessDecision::fromMatches(
            $entries
                ->map(function (AccessListEntry $entry) use (
                    $domain,
                    $normalizedEmail,
                    $normalizedIp,
                ): ?array {
                    $specificity = match ($entry->subject_type) {
                        'email' => $normalizedEmail !== null
                            && hash_equals($entry->value, $normalizedEmail) ? 300 : null,
                        'ip' => $normalizedIp !== null
                            && hash_equals($entry->value, $normalizedIp) ? 300 : null,
                        'domain' => $this->domainSpecificity($domain, $entry->value),
                        default => null,
                    };

                    return $specificity === null
                        ? null
                        : ['entry' => $entry, 'specificity' => $specificity];
                })
                ->filter()
                ->values(),
        );
    }

    public function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    public function normalizeDomain(?string $domain): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return mb_strtolower(rtrim(trim($domain), '.'));
    }

    public function normalizeIp(?string $ip): ?string
    {
        if (! is_string($ip) || filter_var(trim($ip), FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton(trim($ip));

        if ($packed === false) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }

    private function emailDomain(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $domain = strrchr($email, '@');

        return $this->normalizeDomain($domain === false ? null : substr($domain, 1));
    }

    private function domainSpecificity(?string $candidate, string $rule): ?int
    {
        if ($candidate === null) {
            return null;
        }

        $rule = $this->normalizeDomain($rule);
        if ($rule === null) {
            return null;
        }

        if ($candidate === $rule) {
            return 200 + substr_count($rule, '.');
        }

        return str_ends_with($candidate, '.'.$rule)
            ? 100 + substr_count($rule, '.')
            : null;
    }
}
