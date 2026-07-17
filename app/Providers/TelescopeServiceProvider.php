<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if (
                $entry->isRequest()
                && Str::contains(
                    Str::lower((string) ($entry->content['uri'] ?? '')),
                    ['signature=', 'x-amz-signature=', 'token=', 'session_id='],
                )
            ) {
                return false;
            }

            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
            'secret',
            'code',
            'recovery_code',
            'two_factor_code',
            'two_factor_secret',
            'prompt',
            'input',
            'ai_input',
            'body',
            'message',
            'content',
            'cover_letter',
            'notes',
            'reason',
            'rejection_reason',
            'document_content',
            'file',
            'files',
            'attachments',
            'voice_message',
            'credential',
            'credential_json',
            'webauthn',
            'payment_method',
            'payment_method_data',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'proxy-authorization',
            'php-auth-pw',
            'x-csrf-token',
            'x-xsrf-token',
            'x-api-key',
            'stripe-signature',
            'x-signature',
            'x-livekit-signature',
            'openai-api-key',
        ]);

        Telescope::hideResponseParameters([
            'password',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
            'secret',
            'recovery_codes',
            'two_factor_secret',
            'document_content',
            'prompt',
            'input',
            'output',
            'body',
            'message',
            'content',
        ]);
    }

    /**
     * Require an authenticated platform role in every environment.
     */
    protected function authorization()
    {
        $this->gate();

        Telescope::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User
                && Gate::forUser($user)->allows('viewTelescope');
        });
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewTelescope',
            fn (User $user): bool => $user->isPlatformStaff(),
        );
    }
}
