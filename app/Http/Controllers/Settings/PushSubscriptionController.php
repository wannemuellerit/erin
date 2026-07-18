<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PushTestNotification;
use App\Rules\TrustedPushEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionController extends Controller
{
    public function test(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $user->pushSubscriptions()->exists(),
            422,
            __('Aktiviere Browser-Push zuerst auf diesem Gerät.'),
        );
        $user->notify(new PushTestNotification);

        return back()->with('success', __('Die Testbenachrichtigung wurde versendet.'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', new TrustedPushEndpoint],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => [
                'required',
                'string',
                Rule::in(['aes128gcm', 'aesgcm']),
            ],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var array{p256dh: string, auth: string} $keys */
        $keys = $validated['keys'];
        $existing = PushSubscription::findByEndpoint($validated['endpoint']);

        abort_if(
            $existing !== null && ! $user->ownsPushSubscription($existing),
            409,
            __('Diese Browser-Registrierung gehört bereits zu einem anderen Konto.'),
        );

        $user->updatePushSubscription(
            $validated['endpoint'],
            $keys['p256dh'],
            $keys['auth'],
            $validated['content_encoding'],
        );

        return back()->with(
            'success',
            __('Browser-Push wurde für dieses Gerät aktiviert.'),
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url:http,https', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return back()->with(
            'success',
            __('Browser-Push wurde für dieses Gerät deaktiviert.'),
        );
    }
}
