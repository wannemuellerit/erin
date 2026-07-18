<?php

namespace App\Console\Commands;

use App\Services\Ticketing\ZammadEndpoint;
use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckZammadReadinessCommand extends Command
{
    protected $signature = 'erin:zammad:smoke';

    protected $description = 'Prüft die Zammad-Staging-Konfiguration und -Authentifizierung ohne externe Änderungen.';

    /**
     * @var list<array{Prüfung: string, Status: string, Ergebnis: string}>
     */
    private array $rows = [];

    private bool $failed = false;

    public function handle(ZammadWebhookSignature $signatures): int
    {
        $this->rows = [];
        $this->failed = false;

        $enabled = (bool) config('services.zammad.enabled');
        $baseUrl = ZammadEndpoint::configuredBaseUrl();
        $token = trim((string) config('services.zammad.token'));
        $group = trim((string) config('services.zammad.group'));
        $webhookSecret = (string) config('services.zammad.webhook_secret');
        $callbackUrl = ZammadEndpoint::configuredCallbackUrl();

        $this->required(
            'Integration',
            $enabled,
            'ZAMMAD_ENABLED ist aktiv.',
            'ZAMMAD_ENABLED muss für Staging auf true gesetzt werden.',
        );
        $this->required(
            'API-URL',
            $baseUrl !== null,
            'Die Zammad-Basis-URL ist sicher freigegeben.',
            'ZAMMAD_URL muss HTTPS verwenden oder lokal explizit per Host-Allowlist freigegeben sein.',
        );
        $this->required(
            'API-Token',
            $token !== '',
            'Ein dediziertes API-Token ist hinterlegt.',
            'ZAMMAD_TOKEN fehlt.',
        );
        $this->required(
            'Ticketgruppe',
            $group !== '',
            'Eine Zammad-Ticketgruppe ist konfiguriert.',
            'ZAMMAD_GROUP darf nicht leer sein.',
        );
        $this->required(
            'Webhook-Secret',
            strlen($webhookSecret) >= 32,
            'Das Webhook-Secret erfüllt die lokale Mindestlänge.',
            'ZAMMAD_WEBHOOK_SECRET muss mindestens 32 zufällige Zeichen lang sein.',
        );
        $this->required(
            'Erin-Callback',
            $callbackUrl !== null && route('integrations.zammad.webhook', [], false) === '/integrations/zammad/webhook',
            'Der Zammad-Callback ist sicher freigegeben.',
            'ZAMMAD_WEBHOOK_CALLBACK_URL muss HTTPS verwenden oder lokal explizit per Host-Allowlist freigegeben sein.',
        );

        if (strlen($webhookSecret) >= 32) {
            $probe = '{"event":"erin.zammad.readiness"}';
            $signature = $signatures->create($probe, $webhookSecret);
            $valid = $signatures->isValid($probe, $signature, $webhookSecret)
                && ! $signatures->isValid($probe.' ', $signature, $webhookSecret);
            $this->required(
                'Webhook-Signatur',
                $valid,
                'HMAC-SHA1-Erzeugung und Manipulationsschutz funktionieren lokal.',
                'Die lokale HMAC-SHA1-Selbstprüfung ist fehlgeschlagen.',
            );
        }

        if ($enabled && $baseUrl !== null && $token !== '') {
            $this->checkApiAuthentication($baseUrl, $token);
        } else {
            $this->notice(
                'API-Verbindung',
                'Nicht ausgeführt, weil die erforderliche Basiskonfiguration fehlt.',
            );
        }

        $this->notice(
            'Remote-Webhook',
            'Endpoint, Trigger, Gruppe und gleiches HMAC-Secret müssen zusätzlich in Zammad geprüft werden.',
        );

        $this->table(['Prüfung', 'Status', 'Ergebnis'], $this->rows);
        $this->newLine();
        $this->line('Der Smoke-Test verwendet ausschließlich GET /api/v1/users/me und führt keine externen Änderungen aus.');

        if ($this->failed) {
            $this->components->error('Zammad ist noch nicht staging-bereit.');

            return self::FAILURE;
        }

        $this->components->info('Die lokal und read-only prüfbaren Zammad-Voraussetzungen sind erfüllt.');

        return self::SUCCESS;
    }

    private function checkApiAuthentication(string $baseUrl, string $token): void
    {
        $timeout = max(1, min(30, (int) config('services.zammad.timeout', 10)));

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'Token token='.$token])
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get($baseUrl.'/api/v1/users/me');
        } catch (ConnectionException) {
            $this->required(
                'API-Verbindung',
                false,
                '',
                'Zammad ist innerhalb des konfigurierten Zeitlimits nicht erreichbar.',
            );

            return;
        } catch (Throwable) {
            $this->required(
                'API-Verbindung',
                false,
                '',
                'Die read-only Zammad-Anfrage ist ohne Ausgabe externer Antwortdetails fehlgeschlagen.',
            );

            return;
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->required(
                'API-Authentifizierung',
                false,
                '',
                'Zammad hat das API-Token oder dessen Berechtigungen abgelehnt.',
            );

            return;
        }

        if ($response->redirect()) {
            $this->required(
                'API-Verbindung',
                false,
                '',
                'Die API antwortet mit einer Weiterleitung; Weiterleitungen werden zum Schutz des Tokens nicht verfolgt.',
            );

            return;
        }

        if (! $response->successful()) {
            $this->required(
                'API-Verbindung',
                false,
                '',
                sprintf('Die Zammad-API antwortet mit HTTP %d.', $response->status()),
            );

            return;
        }

        $user = $response->json();
        $validUser = is_array($user)
            && isset($user['id'])
            && ($user['active'] ?? true) !== false;
        $this->required(
            'API-Authentifizierung',
            $validUser,
            'Das Token authentifiziert einen aktiven Zammad-Benutzer.',
            'Die API-Antwort enthält keinen aktiven Zammad-Benutzer.',
        );
    }

    private function required(
        string $check,
        bool $passed,
        string $success,
        string $failure,
    ): void {
        $this->rows[] = [
            'Prüfung' => $check,
            'Status' => $passed ? 'OK' : 'FEHLER',
            'Ergebnis' => $passed ? $success : $failure,
        ];
        $this->failed = $this->failed || ! $passed;
    }

    private function notice(string $check, string $message): void
    {
        $this->rows[] = [
            'Prüfung' => $check,
            'Status' => 'HINWEIS',
            'Ergebnis' => $message,
        ];
    }
}
