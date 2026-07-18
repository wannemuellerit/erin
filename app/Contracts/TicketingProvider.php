<?php

namespace App\Contracts;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;

interface TicketingProvider
{
    public function enabled(): bool;

    /**
     * @return array{external_id: string, external_number: string|null, external_article_id: string}
     */
    public function createTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): array;

    /**
     * Reconcile an uncertain create operation without creating another ticket.
     *
     * @return array{external_id: string, external_number: string|null, external_article_id: string}|null
     */
    public function findTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): ?array;

    /**
     * @return array{external_article_id: string}
     */
    public function createMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): array;

    /**
     * Reconcile an uncertain create operation without creating another article.
     *
     * @return array{external_article_id: string}|null
     */
    public function findMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): ?array;

    /**
     * @return array<string, mixed>
     */
    public function article(string $externalArticleId): array;

    /**
     * @param  resource  $destination
     * @return array{mime_type: string|null, size_bytes: int, checksum_sha256: string}
     */
    public function downloadAttachment(
        string $externalTicketId,
        string $externalArticleId,
        string $externalAttachmentId,
        mixed $destination,
        int $maxBytes,
    ): array;
}
