<?php

namespace App\Services\Ticketing;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZammadTicketingProvider implements TicketingProvider
{
    public function enabled(): bool
    {
        return (bool) config('services.zammad.enabled')
            && ZammadEndpoint::secureBaseUrl(config('services.zammad.url')) !== null
            && filled(config('services.zammad.token'));
    }

    public function createTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): array {
        $ticket->loadMissing('requester:id,name,email');
        $response = $this->request(false)->post('/api/v1/tickets', [
            'title' => $ticket->subject,
            'group' => (string) config('services.zammad.group', 'Users'),
            'customer' => $ticket->requester->email,
            'note' => $this->ticketMarker($ticket),
            'article' => [
                'subject' => $this->messageMarker($firstMessage),
                'body' => $firstMessage->body,
                'content_type' => 'text/plain',
                'type' => 'web',
                'internal' => false,
                'sender' => 'Customer',
            ],
        ])->throw()->json();

        if (! is_array($response) || ! isset($response['id'])) {
            throw new RuntimeException('Zammad hat keine gültige Ticket-ID zurückgegeben.');
        }
        $articleIds = is_array($response['article_ids'] ?? null)
            ? array_values($response['article_ids'])
            : [];
        $articleId = isset($articleIds[0]) ? (string) $articleIds[0] : null;

        return [
            'external_id' => (string) $response['id'],
            'external_number' => isset($response['number']) ? (string) $response['number'] : null,
            'external_article_id' => $articleId,
        ];
    }

    public function findTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): ?array {
        $marker = $this->ticketMarker($ticket);
        $response = $this->request()
            ->get('/api/v1/tickets/search', [
                'query' => $marker,
                'full' => 'true',
            ])
            ->throw()
            ->json();
        $tickets = is_array($response['assets']['Ticket'] ?? null)
            ? $response['assets']['Ticket']
            : [];
        $matchedTicketWithoutOpeningArticle = false;

        foreach ($tickets as $candidate) {
            if (
                ! is_array($candidate)
                || ($candidate['note'] ?? null) !== $marker
                || ! isset($candidate['id'])
            ) {
                continue;
            }

            $details = $this->request()
                ->get('/api/v1/tickets/'.rawurlencode((string) $candidate['id']))
                ->throw()
                ->json();
            if (! is_array($details) || ! isset($details['id'])) {
                continue;
            }

            $openingArticle = $this->findArticleByMessageMarker(
                (string) $details['id'],
                $firstMessage,
            );
            if ($openingArticle === null) {
                $matchedTicketWithoutOpeningArticle = true;

                continue;
            }

            return [
                'external_id' => (string) $details['id'],
                'external_number' => isset($details['number'])
                    ? (string) $details['number']
                    : null,
                'external_article_id' => $openingArticle['external_article_id'],
            ];
        }

        if ($matchedTicketWithoutOpeningArticle) {
            throw new RuntimeException(
                'Das Zammad-Ticket wurde gefunden, aber sein markierter Erin-Eröffnungsartikel ist noch nicht verfügbar.',
            );
        }

        return null;
    }

    public function createMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): array {
        if ($ticket->external_id === null) {
            throw new RuntimeException('Das Erin-Ticket besitzt noch keine Zammad-ID.');
        }

        $message->loadMissing('author:id,name,email,role');
        $isStaff = $message->author?->isPlatformStaff() === true;
        $response = $this->request(false)->post('/api/v1/ticket_articles', [
            'ticket_id' => (int) $ticket->external_id,
            'subject' => $this->messageMarker($message),
            'body' => $message->body,
            'content_type' => 'text/plain',
            'type' => $isStaff ? 'note' : 'web',
            'internal' => $message->is_internal,
            'sender' => $isStaff ? 'Agent' : 'Customer',
        ])->throw()->json();

        if (! is_array($response) || ! isset($response['id'])) {
            throw new RuntimeException('Zammad hat keine gültige Artikel-ID zurückgegeben.');
        }

        return ['external_article_id' => (string) $response['id']];
    }

    public function findMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): ?array {
        if ($ticket->external_id === null) {
            return null;
        }

        return $this->findArticleByMessageMarker($ticket->external_id, $message);
    }

    /**
     * @return array{external_article_id: string}|null
     */
    private function findArticleByMessageMarker(
        string $externalTicketId,
        SupportTicketMessage $message,
    ): ?array {
        $articles = $this->request()
            ->get('/api/v1/ticket_articles/by_ticket/'.rawurlencode($externalTicketId))
            ->throw()
            ->json();
        if (! is_array($articles)) {
            return null;
        }

        $marker = $this->messageMarker($message);
        foreach ($articles as $article) {
            if (
                is_array($article)
                && ($article['subject'] ?? null) === $marker
                && isset($article['id'])
            ) {
                return ['external_article_id' => (string) $article['id']];
            }
        }

        return null;
    }

    public function article(string $externalArticleId): array
    {
        $response = $this->request()
            ->get('/api/v1/ticket_articles/'.rawurlencode($externalArticleId))
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Zammad hat keinen gültigen Artikel zurückgegeben.');
        }

        return $response;
    }

    private function ticketMarker(SupportTicket $ticket): string
    {
        return 'Erin operation ticket:'.$ticket->number;
    }

    private function messageMarker(SupportTicketMessage $message): string
    {
        return 'Erin operation message:'.$message->getKey();
    }

    private function request(bool $retry = true): PendingRequest
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Die Zammad-Integration ist nicht vollständig konfiguriert.');
        }

        $baseUrl = ZammadEndpoint::secureBaseUrl(config('services.zammad.url'));
        if ($baseUrl === null) {
            throw new RuntimeException('Die Zammad-URL erfüllt die Sicherheitsanforderungen nicht.');
        }

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Token token='.(string) config('services.zammad.token'),
            ])
            ->withoutRedirecting()
            ->timeout((int) config('services.zammad.timeout', 10));

        return $retry
            ? $request->retry(3, 250, throw: false)
            : $request;
    }
}
