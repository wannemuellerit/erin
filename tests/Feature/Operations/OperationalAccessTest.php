<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

uses(RefreshDatabase::class);

it('allows only platform roles through the Telescope authorization callback in every environment', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $companyUser = User::factory()->create(['role' => UserRole::Company]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $requestFor = static function (?User $user): Request {
        $request = Request::create('/telescope');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    expect(Telescope::check($requestFor(null)))->toBeFalse()
        ->and(Telescope::check($requestFor($candidate)))->toBeFalse()
        ->and(Telescope::check($requestFor($companyUser)))->toBeFalse()
        ->and(Telescope::check($requestFor($support)))->toBeTrue()
        ->and(Telescope::check($requestFor($admin)))->toBeTrue();
});

it('keeps the internal system overview restricted to platform roles', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $support = User::factory()->create(['role' => UserRole::Support]);

    $this->actingAs($candidate)->get(route('admin.system.index'))->assertForbidden();
    $this->actingAs($support)->get(route('admin.system.index'))->assertOk();
});

it('registers request and response scrubbing for credentials, provider keys and sensitive AI data', function () {
    expect(Telescope::$hiddenRequestHeaders)
        ->toContain(
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-api-key',
            'stripe-signature',
            'x-livekit-signature',
        )
        ->and(Telescope::$hiddenRequestParameters)
        ->toContain(
            'password',
            'current_password',
            'recovery_code',
            'two_factor_secret',
            'api_key',
            'client_secret',
            'prompt',
            'input',
            'body',
            'message',
            'document_content',
            'attachments',
            'credential',
            'payment_method_data',
        )
        ->and(Telescope::$hiddenResponseParameters)
        ->toContain(
            'access_token',
            'refresh_token',
            'client_secret',
            'recovery_codes',
            'two_factor_secret',
            'document_content',
            'output',
        )
        ->and(config('telescope.middleware'))
        ->toContain('auth', 'staff.2fa')
        ->and(config('telescope.ignore_paths'))
        ->toContain(
            'stripe*',
            'documents/*/download*',
            'messages/attachments/*',
            'interviews/*/calendar.ics*',
            'company-invitations/*',
        );
});

it('drops signed URLs before Telescope can persist their query secrets', function () {
    $entry = IncomingEntry::make([
        'uri' => '/documents/42/download?expires=123456&signature=do-not-store',
        'response_status' => 200,
    ])->type(EntryType::REQUEST);

    $accepted = collect(Telescope::$filterUsing)
        ->every(fn (callable $filter): bool => $filter($entry));

    expect($accepted)->toBeFalse();
});
