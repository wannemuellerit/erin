<?php

use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('serves the public home and pricing pages with the negotiated locale', function () {
    $this->withHeader('Accept-Language', 'en')
        ->get(route('home'))
        ->assertOk()
        ->assertSessionHas('locale', 'en')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('platform.locale', 'en'));

    $this->withSession(['locale' => 'de'])
        ->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->has('plans')
            ->where('platform.locale', 'de'));
});

it('lets guests persist a supported locale and rejects unsupported locales', function () {
    $this->from(route('home'))
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('locale', 'en');

    $this->from(route('home'))
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('locale');
});

it('also persists the selected locale for authenticated users', function () {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('locale', 'en');

    expect($user->refresh()->locale)->toBe('en');
});

it('serves every legal route with an explicit unpublished launch gate', function (
    string $routeName,
    string $document,
) {
    $this->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal')
            ->where('document', $document)
            ->where('published', false)
            ->where('content', null));
})->with([
    'privacy' => ['legal.privacy', 'privacy'],
    'imprint' => ['legal.imprint', 'imprint'],
    'terms' => ['legal.terms', 'terms'],
]);

it('publishes only explicitly public and approved localized legal content', function () {
    $settings = app(PlatformSettings::class);
    $settings->put(
        'legal.privacy',
        [
            'published' => true,
            'content_de' => 'Freigegebener deutscher Datenschutztext.',
            'content_en' => 'Approved English privacy text.',
        ],
        'legal',
        false,
    );

    $this->withSession(['locale' => 'en'])
        ->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('published', false)
            ->where('content', null));

    $settings->put(
        'legal.privacy',
        [
            'published' => true,
            'content_de' => 'Freigegebener deutscher Datenschutztext.',
            'content_en' => 'Approved English privacy text.',
        ],
        'legal',
        true,
    );

    $this->withSession(['locale' => 'en'])
        ->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('published', true)
            ->where('content', 'Approved English privacy text.'));
});

it('replaces the dead contact redirect with a configurable public contact page', function () {
    $this->get(route('contact'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Contact')
            ->where('contact.available', false)
            ->where('contact.email', null)
            ->where('contact.phone', null)
            ->where('contact.address', null));

    app(PlatformSettings::class)->put(
        'public.contact',
        [
            'email' => 'recruiting@example.test',
            'phone' => '+49 30 123456',
            'address_de' => "Erin Recruiting\nBerlin",
            'address_en' => "Erin Recruiting\nBerlin, Germany",
        ],
        'contact',
        true,
    );

    $this->withSession(['locale' => 'en'])
        ->get(route('contact'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Contact')
            ->where('contact.available', true)
            ->where('contact.email', 'recruiting@example.test')
            ->where('contact.phone', '+49 30 123456')
            ->where('contact.address', "Erin Recruiting\nBerlin, Germany"));
});
