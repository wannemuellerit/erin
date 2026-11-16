<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Stripe\Exception\AuthenticationException;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

it('returns to account settings after resending verification for an unverified account', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($user)->from(route('profile.edit'))->post(route('verification.send'))
        ->assertRedirect(route('profile.edit'))->assertSessionHas('status', 'verification-link-sent');
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('returns a safe referral delivery error and can retry successfully', function () {
    $user = User::factory()->create(['role' => UserRole::Candidate, 'onboarding_completed_at' => now()]);
    $user->referralCodes()->create(['code' => 'tester-link', 'is_active' => true, 'commission_cents' => 0, 'currency' => 'EUR']);
    Mail::shouldReceive('raw')->once()->andThrow(new TransportException('secret SMTP diagnostics'));
    $this->actingAs($user)->post(route('referrals.email'), ['email' => 'recipient@example.test'])
        ->assertSessionHasErrors('email')->assertSessionMissing('success');
    Mail::shouldReceive('raw')->once()->andReturnNull();
    $this->post(route('referrals.email'), ['email' => 'recipient@example.test'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
});

it('keeps expected action rejections inside Inertia but preserves JSON errors', function () {
    Route::middleware('web')->post('/tester-rejection', fn () => abort(422, 'Test rejection'));
    $this->from('/employer/billing')->withHeader('X-Inertia', 'true')
        ->post('/tester-rejection')->assertRedirect('/employer/billing')->assertSessionHasErrors('action');
    $this->postJson('/tester-rejection')->assertUnprocessable()->assertJsonPath('message', 'Test rejection');
});

it('handles Stripe failures without exposing credentials or claiming payment success', function () {
    Route::middleware('web')->post('/tester-stripe', fn () => throw AuthenticationException::factory('secret Stripe diagnostics'))
        ->name('employer.billing.tester');
    $this->from('/employer/billing')->post('/tester-stripe')
        ->assertRedirect('/employer/billing')->assertSessionHas('error')->assertSessionMissing('success');
    expect(session('error'))->not->toContain('secret');
    $this->postJson('/tester-stripe')->assertStatus(503)->assertDontSee('secret');
});
