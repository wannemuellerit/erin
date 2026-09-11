<?php

namespace App\Services\Notifications;

use App\Contracts\ExternalMessageProvider;
use App\Data\ExternalMessageRequest;
use App\Data\ExternalMessageResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

final readonly class TwilioMessageProvider implements ExternalMessageProvider
{
    public function __construct(private HttpFactory $http) {}

    public function send(ExternalMessageRequest $request): ExternalMessageResult
    {
        $accountSid = (string) config('services.twilio.account_sid');
        $authToken = (string) config('services.twilio.auth_token');
        $from = $request->channel === 'whatsapp'
            ? (string) config('services.twilio.whatsapp_from')
            : (string) config('services.twilio.sms_from');
        if ($accountSid === '' || $authToken === '' || $from === '') {
            throw new RuntimeException('External message provider is not configured.');
        }

        $response = $this->http
            ->withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false)
            ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'To' => $request->channel === 'whatsapp' ? 'whatsapp:'.$request->to : $request->to,
                'From' => $request->channel === 'whatsapp' ? 'whatsapp:'.$from : $from,
                'Body' => $request->body,
                'StatusCallback' => route('integrations.external-notifications.webhook', ['provider' => 'twilio']),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('External message provider request failed with status '.$response->status().'.');
        }

        return new ExternalMessageResult(
            provider: 'twilio',
            messageId: (string) $response->json('sid'),
            status: (string) ($response->json('status') ?: 'queued'),
        );
    }
}
