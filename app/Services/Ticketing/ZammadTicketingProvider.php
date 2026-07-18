<?php

namespace App\Services\Ticketing;

use App\Contracts\TicketingProvider;
use App\Exceptions\SupportAttachmentLimitExceeded;
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
            && ZammadEndpoint::configuredBaseUrl() !== null
            && filled(config('services.zammad.token'));
    }

    public function createTicket(
        SupportTicket $ticket,
        SupportTicketMessage $firstMessage,
    ): array {
        $ticket->loadMissing('requester:id,name,email');
        $article = [
            'subject' => $this->messageMarker($firstMessage),
            'body' => $firstMessage->body,
            'content_type' => 'text/plain',
            'type' => 'web',
            'internal' => false,
            'sender' => 'Customer',
        ];
        $attachments = app(SupportAttachmentPayloadBuilder::class)
            ->forMessage($firstMessage);
        if ($attachments !== []) {
            $article['attachments'] = $attachments;
        }
        $response = $this->request(false)->post('/api/v1/tickets', [
            'title' => $ticket->subject,
            'group' => (string) config('services.zammad.group', 'Users'),
            'customer_id' => 'guess:'.$ticket->requester->email,
            'note' => $this->ticketMarker($ticket),
            'article' => $article,
        ])->throw()->json();

        if (
            ! is_array($response)
            || ! $this->validId($response['id'] ?? null)
        ) {
            throw new RuntimeException('Zammad hat keine gültige Ticket-ID zurückgegeben.');
        }
        $articleIds = is_array($response['article_ids'] ?? null)
            ? array_values($response['article_ids'])
            : [];
        $articleId = $articleIds[0] ?? null;
        if (! $this->validId($articleId)) {
            throw new RuntimeException(
                'Zammad hat keine gültige ID für den Eröffnungsartikel zurückgegeben.',
            );
        }

        return [
            'external_id' => (string) $response['id'],
            'external_number' => isset($response['number']) ? (string) $response['number'] : null,
            'external_article_id' => (string) $articleId,
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
        $matches = [];
        $inspectedTicketIds = [];

        foreach ($tickets as $candidate) {
            if (
                ! is_array($candidate)
                || ($candidate['note'] ?? null) !== $marker
                || ! $this->validId($candidate['id'] ?? null)
            ) {
                continue;
            }

            $externalTicketId = (string) $candidate['id'];
            if (isset($inspectedTicketIds[$externalTicketId])) {
                continue;
            }
            $inspectedTicketIds[$externalTicketId] = true;

            $details = $this->request()
                ->get('/api/v1/tickets/'.rawurlencode($externalTicketId))
                ->throw()
                ->json();
            if (
                ! is_array($details)
                || ! $this->validId($details['id'] ?? null)
            ) {
                continue;
            }

            $resolvedExternalTicketId = (string) $details['id'];
            if (! hash_equals($externalTicketId, $resolvedExternalTicketId)) {
                throw new RuntimeException(
                    'Die Zammad-Ticketdetails widersprechen der angefragten Ticket-ID.',
                );
            }
            $openingArticle = $this->findArticleByMessageMarker(
                $resolvedExternalTicketId,
                $firstMessage,
            );
            if ($openingArticle === null) {
                $matchedTicketWithoutOpeningArticle = true;

                continue;
            }

            $matches[$resolvedExternalTicketId] = [
                'external_id' => $resolvedExternalTicketId,
                'external_number' => isset($details['number'])
                    ? (string) $details['number']
                    : null,
                'external_article_id' => $openingArticle['external_article_id'],
            ];
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Mehrere Zammad-Tickets besitzen denselben Erin-Zustellmarker.',
            );
        }
        if ($matches !== []) {
            return array_values($matches)[0];
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
        $payload = [
            'ticket_id' => (int) $ticket->external_id,
            'subject' => $this->messageMarker($message),
            'body' => $message->body,
            'content_type' => 'text/plain',
            'type' => $isStaff ? 'note' : 'web',
            'internal' => $message->is_internal,
            'sender' => $isStaff ? 'Agent' : 'Customer',
        ];
        $attachments = app(SupportAttachmentPayloadBuilder::class)
            ->forMessage($message);
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        $response = $this->request(false)
            ->post('/api/v1/ticket_articles', $payload)
            ->throw()
            ->json();

        if (
            ! is_array($response)
            || ! $this->validId($response['id'] ?? null)
        ) {
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

        $message->loadMissing('author:id,role');
        $markers = app(ZammadMessageMarker::class)->verificationMarkersFor($message);
        $expectedSender = $message->author?->isPlatformStaff() === true
            ? 'agent'
            : 'customer';
        $matches = [];
        foreach ($articles as $article) {
            if (
                is_array($article)
                && in_array($article['subject'] ?? null, $markers, true)
                && $this->validId($article['id'] ?? null)
                && is_string($article['sender'] ?? null)
                && mb_strtolower($article['sender']) === $expectedSender
                && is_bool($article['internal'] ?? null)
                && $article['internal'] === $message->is_internal
                && is_string($article['body'] ?? null)
                && hash_equals(
                    trim($message->body),
                    $this->plainText($article['body']),
                )
            ) {
                $matches[(string) $article['id']] = [
                    'external_article_id' => (string) $article['id'],
                ];
            }
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Mehrere Zammad-Artikel besitzen denselben Erin-Zustellmarker.',
            );
        }

        return $matches === [] ? null : array_values($matches)[0];
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

    public function downloadAttachment(
        string $externalTicketId,
        string $externalArticleId,
        string $externalAttachmentId,
        mixed $destination,
        int $maxBytes,
    ): array {
        if (! is_resource($destination)) {
            throw new RuntimeException('Für den Zammad-Anhang wurde kein gültiger Zielstream übergeben.');
        }
        if ($maxBytes < 1) {
            throw new SupportAttachmentLimitExceeded(
                'Für den Zammad-Anhang wurde keine gültige Dateigrenze festgelegt.',
            );
        }

        $response = $this->request()
            ->accept('*/*')
            ->withOptions(['stream' => true])
            ->get(sprintf(
                '/api/v1/ticket_attachment/%s/%s/%s',
                rawurlencode($externalTicketId),
                rawurlencode($externalArticleId),
                rawurlencode($externalAttachmentId),
            ))
            ->throw();
        $mimeType = trim(explode(';', $response->header('Content-Type'), 2)[0]);
        $source = $response->toPsrResponse()->getBody();
        $size = 0;
        $hash = hash_init('sha256');

        while (! $source->eof()) {
            $chunk = $source->read(64 * 1024);
            if ($chunk === '') {
                break;
            }

            $nextSize = $size + strlen($chunk);
            if ($nextSize > $maxBytes) {
                throw new SupportAttachmentLimitExceeded(
                    'Der Zammad-Anhang überschreitet die erlaubte Einzelgröße.',
                );
            }
            if (fwrite($destination, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Der Zammad-Anhang konnte nicht vollständig zwischengespeichert werden.');
            }

            $size = $nextSize;
            hash_update($hash, $chunk);
        }
        rewind($destination);

        return [
            'mime_type' => $mimeType !== '' ? $mimeType : null,
            'size_bytes' => $size,
            'checksum_sha256' => hash_final($hash),
        ];
    }

    private function ticketMarker(SupportTicket $ticket): string
    {
        return 'Erin operation ticket:'.$ticket->number;
    }

    private function messageMarker(SupportTicketMessage $message): string
    {
        return app(ZammadMessageMarker::class)->for($message);
    }

    private function validId(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) === 1;
    }

    private function plainText(string $body): string
    {
        $body = preg_replace('/<br\s*\/?>/i', "\n", $body) ?? $body;

        return trim(html_entity_decode(
            strip_tags($body),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    private function request(bool $retry = true): PendingRequest
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Die Zammad-Integration ist nicht vollständig konfiguriert.');
        }

        $baseUrl = ZammadEndpoint::configuredBaseUrl();
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
