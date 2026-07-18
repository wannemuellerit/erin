<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\StripeCatalogGateway;
use App\Contracts\StripeSubscriptionGateway;
use App\Contracts\TicketingProvider;
use App\Contracts\VideoProvider;
use App\Http\Controllers\Integrations\StripeWebhookController;
use App\Models\Company;
use App\Services\Ai\OpenAiResponsesProvider;
use App\Services\Billing\StripeApiCatalogGateway;
use App\Services\Billing\StripeApiSubscriptionGateway;
use App\Services\Ticketing\NullTicketingProvider;
use App\Services\Ticketing\ZammadTicketingProvider;
use App\Services\Video\LiveKitVideoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, OpenAiResponsesProvider::class);
        $this->app->bind(StripeCatalogGateway::class, StripeApiCatalogGateway::class);
        $this->app->bind(
            StripeSubscriptionGateway::class,
            StripeApiSubscriptionGateway::class,
        );
        $this->app->bind(
            CashierWebhookController::class,
            StripeWebhookController::class,
        );
        $this->app->bind(VideoProvider::class, LiveKitVideoProvider::class);
        $this->app->bind(
            TicketingProvider::class,
            fn (): TicketingProvider => (bool) config('services.zammad.enabled')
                ? new ZammadTicketingProvider
                : new NullTicketingProvider,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Company::class);
        Cashier::calculateTaxes();

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
