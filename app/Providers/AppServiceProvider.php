<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\StripeBillingChangeGateway;
use App\Contracts\StripeCatalogGateway;
use App\Contracts\StripeSubscriptionGateway;
use App\Contracts\TicketingProvider;
use App\Contracts\VideoProvider;
use App\Http\Controllers\Integrations\StripeWebhookController;
use App\Models\Company;
use App\Services\Ai\OpenAiResponsesProvider;
use App\Services\Billing\StripeApiBillingChangeGateway;
use App\Services\Billing\StripeApiCatalogGateway;
use App\Services\Billing\StripeApiSubscriptionGateway;
use App\Services\Ticketing\NullTicketingProvider;
use App\Services\Ticketing\ZammadTicketingProvider;
use App\Services\Video\LiveKitVideoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Stripe;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, OpenAiResponsesProvider::class);
        $this->app->bind(
            StripeBillingChangeGateway::class,
            static fn (): StripeApiBillingChangeGateway => new StripeApiBillingChangeGateway,
        );
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
        Stripe::setMaxNetworkRetries(
            (int) config('cashier.network_retries', 2),
        );
        Queue::before(static function (JobProcessing $event): void {
            Log::withContext([
                'queue_connection' => $event->connectionName,
                'queue_name' => $event->job->getQueue(),
                'job_uuid' => $event->job->uuid(),
            ])->info('queue.job.processing');
        });
        Queue::after(static function (JobProcessed $event): void {
            Log::info('queue.job.processed', [
                'queue_connection' => $event->connectionName,
                'queue_name' => $event->job->getQueue(),
                'job_uuid' => $event->job->uuid(),
            ]);
        });
        Queue::exceptionOccurred(static function (JobExceptionOccurred $event): void {
            Log::error('queue.job.failed', [
                'queue_connection' => $event->connectionName,
                'queue_name' => $event->job->getQueue(),
                'job_uuid' => $event->job->uuid(),
                'exception_class' => $event->exception::class,
            ]);
        });

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
