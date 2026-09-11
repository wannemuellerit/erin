<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupportKnowledgeArticle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @param Builder<SupportKnowledgeArticle> $query */
    public function scopePublished(Builder $query, string $locale, string $role): void
    {
        $query
            ->where('locale', $locale)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('target_roles')->orWhereJsonContains('target_roles', $role));
    }
}
