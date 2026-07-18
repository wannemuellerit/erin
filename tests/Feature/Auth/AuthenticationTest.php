<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_demo_credentials_can_be_disabled_for_non_demo_environments(): void
    {
        config()->set('app.demo_mode', false);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('demoMode', false)
                ->where('demoAccounts', [])
                ->where('demoPassword', ''));
    }

    public function test_login_screen_exposes_every_seeded_demo_account_only_in_demo_mode(): void
    {
        config()->set('app.demo_mode', true);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('demoMode', true)
                ->where('demoPassword', 'password')
                ->has('demoAccounts', 13)
                ->where('demoAccounts.0.email', 'admin@wannemueller.dev')
                ->where('demoAccounts.1.email', 'unternehmen.mueller@wannemueller.dev')
                ->where('demoAccounts.2.email', 'unternehmen.rheincargo@wannemueller.dev')
                ->where('demoAccounts.12.email', 'candidate10@wannemueller.dev'));
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_seeded_demo_user_can_authenticate(): void
    {
        config()->set('app.demo_mode', true);

        $this->seed([
            DomainCatalogSeeder::class,
            DemoDataSeeder::class,
        ]);

        $response = $this->post(route('login.store'), [
            'email' => 'admin@wannemueller.dev',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs(
            User::query()->where('email', 'admin@wannemueller.dev')->firstOrFail(),
        );
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_every_login_hint_matches_a_seeded_account_and_password(): void
    {
        config()->set('app.demo_mode', true);

        $this->seed([
            DomainCatalogSeeder::class,
            DemoDataSeeder::class,
        ]);

        /** @var array<int, array{email: string}> $accounts */
        $accounts = config('demo.accounts', []);
        $seededUsers = User::query()
            ->whereIn('email', array_column($accounts, 'email'))
            ->get()
            ->keyBy('email');

        expect($seededUsers)->toHaveCount(count($accounts));

        foreach ($accounts as $account) {
            $user = $seededUsers->get($account['email']);

            expect($user)->not->toBeNull()
                ->and(Hash::check((string) config('demo.password'), $user->password))->toBeTrue();
        }
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
