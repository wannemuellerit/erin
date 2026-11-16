<?php

use Illuminate\Support\Facades\Route;

it('exposes only the signed interview ICS calendar workflow', function () {
    expect(Route::getRoutes()->getByName('interviews.ics'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('calendar.connect'))->toBeNull()
        ->and(Route::getRoutes()->getByName('calendar.callback'))->toBeNull()
        ->and(Route::getRoutes()->getByName('calendar.connections.update'))->toBeNull()
        ->and(Route::getRoutes()->getByName('calendar.connections.destroy'))->toBeNull()
        ->and(Route::getRoutes()->getByName('integrations.calendar.webhook'))->toBeNull();

    $icsRoute = Route::getRoutes()->getByName('interviews.ics');

    expect($icsRoute?->gatherMiddleware())
        ->toContain('auth', 'verified', 'signed')
        ->and((string) file_get_contents(base_path('.env.example')))
        ->not->toContain('GOOGLE_CALENDAR_', 'MICROSOFT_CALENDAR_', 'CALENDAR_WEBHOOK');
});
