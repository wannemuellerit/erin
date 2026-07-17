<?php

namespace Tests\Feature\Auth;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_company_registration_creates_a_company_owner_membership(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'business',
            'is_active' => true,
            'is_enterprise' => false,
        ]);
        $response = $this->post(route('register.store'), [
            'name' => 'Marie Recruiter',
            'email' => 'marie@company.example',
            'role' => UserRole::Company->value,
            'company_name' => 'Recruiting Beispiel GmbH',
            'plan_slug' => $plan->slug,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'marie@company.example')->firstOrFail();
        $company = Company::query()->where('name', 'Recruiting Beispiel GmbH')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRole::Company, $user->role);
        $this->assertSame($plan->getKey(), $company->current_plan_id);
        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'role' => CompanyMemberRole::Owner->value,
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
