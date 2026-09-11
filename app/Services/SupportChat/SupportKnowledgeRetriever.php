<?php

namespace App\Services\SupportChat;

use App\Models\SupportKnowledgeArticle;
use Illuminate\Support\Collection;

final class SupportKnowledgeRetriever
{
    /**
     * @return Collection<int, SupportKnowledgeArticle>
     */
    public function retrieve(string $question, string $locale, string $role): Collection
    {
        $tokens = collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($question)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values();

        return SupportKnowledgeArticle::query()
            ->published($locale, $role)
            ->get()
            ->groupBy('stable_key')
            ->map(fn (Collection $versions): SupportKnowledgeArticle => $versions->sortByDesc('version')->first())
            ->map(function (SupportKnowledgeArticle $article) use ($tokens): SupportKnowledgeArticle {
                $haystack = mb_strtolower($article->title.' '.$article->body);
                $article->setAttribute('_relevance', $tokens->sum(
                    fn (string $token): int => substr_count($haystack, $token),
                ));

                return $article;
            })
            ->filter(fn (SupportKnowledgeArticle $article): bool => (int) $article->getAttribute('_relevance') > 0)
            ->sortByDesc('_relevance')
            ->take(max(1, (int) config('support.chatbot.max_sources', 3)))
            ->values();
    }
}
