<?php

namespace App\Services\Ticketing;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use LogicException;

class NullTicketingProvider implements TicketingProvider
{
    public function enabled(): bool
    {
        return false;
    }

    public function createTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): array {
        throw new LogicException('Es ist kein externes Ticketsystem konfiguriert.');
    }

    public function findTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): ?array {
        return null;
    }

    public function createMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): array {
        throw new LogicException('Es ist kein externes Ticketsystem konfiguriert.');
    }

    public function findMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): ?array {
        return null;
    }

    public function article(string $externalArticleId): array
    {
        throw new LogicException('Es ist kein externes Ticketsystem konfiguriert.');
    }

    public function downloadAttachment(
        string $externalTicketId,
        string $externalArticleId,
        string $externalAttachmentId,
        mixed $destination,
        int $maxBytes,
    ): array {
        throw new LogicException('Es ist kein externes Ticketsystem konfiguriert.');
    }
}
