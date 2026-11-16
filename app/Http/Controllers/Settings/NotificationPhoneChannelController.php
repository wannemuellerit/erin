<?php

namespace App\Http\Controllers\Settings;

use App\Contracts\ExternalMessageProvider;
use App\Data\ExternalMessageRequest;
use App\Http\Controllers\Controller;
use App\Models\NotificationPhoneChannel;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationPhoneChannelController extends Controller
{
    public function store(
        Request $request,
        ExternalMessageProvider $provider,
        ActivityRecorder $activity,
    ): RedirectResponse {
        abort_unless((bool) config('services.twilio.enabled', false), 503, __('Telefonbenachrichtigungen sind noch nicht freigeschaltet.'));
        $validated = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'country_code' => ['required', 'string', 'size:2'],
            'consent' => ['accepted'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);
        $allowedCountries = config('services.twilio.allowed_countries', ['DE']);
        abort_unless(is_array($allowedCountries) && in_array(strtoupper($validated['country_code']), $allowedCountries, true), 422, __('Dieses Land ist für Telefonbenachrichtigungen noch nicht freigegeben.'));
        $user = $request->user();
        abort_if($user === null, 401);
        $code = (string) random_int(100000, 999999);
        $phoneHash = hash('sha256', $validated['phone']);
        $channel = $user->notificationPhoneChannels()->where('channel', $validated['channel'])->first();
        $phoneChanged = $channel === null || $channel->phone_hash !== $phoneHash;
        $existingVerifiedAt = $channel instanceof NotificationPhoneChannel ? $channel->verified_at : null;
        $channel = $user->notificationPhoneChannels()->updateOrCreate(
            ['channel' => $validated['channel']],
            [
                'phone_e164' => $validated['phone'],
                'phone_hash' => $phoneHash,
                'country_code' => strtoupper($validated['country_code']),
                'verification_code_hash' => hash_hmac('sha256', $code, (string) config('app.key')),
                'verification_expires_at' => now()->addMinutes(10),
                'verified_at' => $phoneChanged ? null : $existingVerifiedAt,
                'consented_at' => now(),
                'consent_version' => 'phone-notifications-v1',
                'revoked_at' => null,
                'quiet_hours_start' => $validated['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $validated['quiet_hours_end'] ?? null,
            ],
        );
        $provider->send(new ExternalMessageRequest(
            channel: $validated['channel'],
            to: $validated['phone'],
            body: __('Dein Faden-Bestätigungscode lautet :code. Er ist 10 Minuten gültig.', [
                'code' => $code,
            ], $user->locale),
            idempotencyKey: hash('sha256', 'verify|'.$channel->getKey().'|'.$channel->updated_at?->getTimestamp()),
            locale: in_array($user->locale, config('app.supported_locales'), true)
                ? $user->locale
                : 'de',
            templateKey: $validated['channel'] === 'whatsapp' ? 'phone_verification' : null,
        ));
        $activity->record(
            'notification.phone_verification_sent',
            $user,
            null,
            $channel,
            ['channel' => $validated['channel'], 'country_code' => strtoupper($validated['country_code'])],
            $user,
            'private',
        );

        return back()->with('success', __('Der Bestätigungscode wurde versendet.'));
    }

    public function verify(Request $request, NotificationPhoneChannel $channel, ActivityRecorder $activity): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($channel->user_id === $user->getKey(), 404);
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $valid = $channel->verification_expires_at?->isFuture() === true
            && hash_equals(
                (string) $channel->verification_code_hash,
                hash_hmac('sha256', $validated['code'], (string) config('app.key')),
            );
        if (! $valid) {
            return back()->withErrors(['code' => __('Der Bestätigungscode ist ungültig oder abgelaufen.')]);
        }
        $channel->update([
            'verified_at' => now(),
            'verification_code_hash' => null,
            'verification_expires_at' => null,
        ]);
        $activity->record(
            'notification.phone_verified',
            $user,
            null,
            $channel,
            ['channel' => $channel->channel, 'country_code' => $channel->country_code],
            $user,
            'private',
        );

        return back()->with('success', __('Telefonnummer wurde verifiziert.'));
    }

    public function destroy(Request $request, NotificationPhoneChannel $channel, ActivityRecorder $activity): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($channel->user_id === $user->getKey(), 404);
        DB::transaction(function () use ($user, $channel): void {
            $channel->update(['revoked_at' => now(), 'consented_at' => null]);
            $column = $channel->channel === 'whatsapp' ? 'whatsapp_enabled' : 'sms_enabled';
            $user->notificationPreferences()->update([$column => false]);
        });
        $activity->record(
            'notification.phone_consent_revoked',
            $user,
            null,
            $channel,
            ['channel' => $channel->channel, 'country_code' => $channel->country_code],
            $user,
            'private',
        );

        return back()->with('success', __('Einwilligung wurde widerrufen.'));
    }
}
