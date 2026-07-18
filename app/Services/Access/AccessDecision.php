<?php

namespace App\Services\Access;

use App\Models\AccessListEntry;
use Illuminate\Support\Collection;

final readonly class AccessDecision
{
    public function __construct(
        public bool $allowed,
        public ?AccessListEntry $matchedEntry = null,
    ) {}

    public function blocked(): bool
    {
        return ! $this->allowed;
    }

    public static function allow(?AccessListEntry $entry = null): self
    {
        return new self(true, $entry);
    }

    public static function deny(AccessListEntry $entry): self
    {
        return new self(false, $entry);
    }

    /**
     * @param  Collection<int, array{entry: AccessListEntry, specificity: int}>  $matches
     */
    public static function fromMatches(Collection $matches): self
    {
        if ($matches->isEmpty()) {
            return self::allow();
        }

        $winner = $matches
            ->sortByDesc(fn (array $match): string => sprintf(
                '%04d-%d-%020d',
                $match['specificity'],
                $match['entry']->list_type === 'whitelist' ? 1 : 0,
                $match['entry']->getKey(),
            ))
            ->first();

        return $winner['entry']->list_type === 'whitelist'
            ? self::allow($winner['entry'])
            : self::deny($winner['entry']);
    }
}
