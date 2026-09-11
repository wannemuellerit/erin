<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\Route;

it('renders an unknown browser path in the Faden error design', function () {
    $this->get('/dieser-pfad-existiert-nicht')
        ->assertNotFound()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('X-Inertia')
        ->assertSee('Faden Logo')
        ->assertSee('src="'.url('/favicon.svg').'"', false)
        ->assertSee('--erin-primary: #2563eb;', false)
        ->assertSee('Seite nicht gefunden')
        ->assertDontSee('Fehler 404')
        ->assertSee('Der aufgerufene Pfad konnte nicht gefunden werden.')
        ->assertSee('href="'.url('/').'"', false)
        ->assertSee('hier');
});

it('renders unknown Inertia navigations as a Vue page with the current app context', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ])
        ->get('/dieser-inertia-pfad-existiert-nicht')
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('component', 'errors/NotFound')
        ->assertJsonPath('props.status', 404)
        ->assertJsonPath('props.auth.user.id', $user->id)
        ->assertJsonPath('props.platform.locale', 'en');
});

it('renders forbidden browser pages with the same 404 design', function () {
    Route::middleware('web')->get('/__tests/forbidden-page', function (): never {
        abort(403);
    });

    $this->get('/__tests/forbidden-page')
        ->assertForbidden()
        ->assertSee('Seite nicht gefunden')
        ->assertDontSee('Fehler 403')
        ->assertDontSee('Forbidden');
});

it('renders forbidden Inertia navigations as the Vue error page while preserving 403', function () {
    Route::middleware('web')->get('/__tests/forbidden-inertia-page', function (): never {
        abort(403);
    });

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
    ])
        ->get('/__tests/forbidden-inertia-page')
        ->assertForbidden()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'errors/NotFound')
        ->assertJsonPath('props.status', 403);
});

it('keeps the machine-readable forbidden status for json consumers', function () {
    Route::middleware('web')->get('/__tests/forbidden-api', function (): never {
        abort(403);
    });

    $this->getJson('/__tests/forbidden-api')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('keeps unknown api paths machine-readable instead of using the browser fallback', function () {
    $this->getJson('/api/dieser-pfad-existiert-nicht')
        ->assertNotFound()
        ->assertJsonStructure(['message'])
        ->assertHeaderMissing('X-Inertia');
});

it('renders unexpected production errors in the Faden error design', function () {
    config()->set('app.debug', false);
    Route::middleware('web')->get('/__tests/broken-page', function (): never {
        throw new RuntimeException('Sensitive internal failure');
    });

    $this->get('/__tests/broken-page')
        ->assertInternalServerError()
        ->assertSee('Faden Logo')
        ->assertSee('Wir sind kaputt. Aber ein Techniker arbeitet bereits an einer Lösung.')
        ->assertDontSee('Fehler 500')
        ->assertDontSee('Sensitive internal failure');
});

it('keeps the Blade 500 fallback for failed Inertia requests', function () {
    config()->set('app.debug', false);
    Route::middleware('web')->get('/__tests/broken-inertia-page', function (): never {
        throw new RuntimeException('Sensitive Inertia failure');
    });
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
    ])->get('/__tests/broken-inertia-page')
        ->assertInternalServerError()
        ->assertHeaderMissing('X-Inertia')
        ->assertSee('Faden Logo')
        ->assertSee('Wir sind kaputt. Aber ein Techniker arbeitet bereits an einer Lösung.')
        ->assertDontSee('Fehler 500')
        ->assertDontSee('Sensitive Inertia failure');
});
