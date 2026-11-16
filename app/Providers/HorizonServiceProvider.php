<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $alertEmail = config('horizon.alerts_email');
        if (is_string($alertEmail) && $alertEmail !== '') {
            Horizon::routeMailNotificationsTo($alertEmail);
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            fn (?User $user = null): bool => $user?->role === UserRole::SuperAdmin
                && $user->two_factor_confirmed_at !== null,
        );
    }
}
