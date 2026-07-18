<?php

namespace App\Services\Ticketing;

use App\Models\SupportZammadArticleReceipt;
use Carbon\CarbonInterface;
use RuntimeException;

final class ZammadArticleReceiptRecorder
{
    public function record(
        int $supportTicketId,
        string $externalArticleId,
        bool $isInternal,
        ?CarbonInterface $articleUpdatedAt = null,
    ): SupportZammadArticleReceipt {
        $receipt = SupportZammadArticleReceipt::query()->createOrFirst(
            ['external_article_id' => $externalArticleId],
            [
                'support_ticket_id' => $supportTicketId,
                'is_internal' => $isInternal,
                'article_updated_at' => $articleUpdatedAt,
            ],
        );

        if (
            $receipt->support_ticket_id !== $supportTicketId
            || $receipt->is_internal !== $isInternal
        ) {
            throw new RuntimeException(
                'Ein Zammad-Artikelbeleg widerspricht seiner bestehenden Ticketzuordnung.',
            );
        }

        if ($receipt->article_updated_at === null && $articleUpdatedAt !== null) {
            $receipt->forceFill(['article_updated_at' => $articleUpdatedAt])->save();
        }

        return $receipt;
    }
}
