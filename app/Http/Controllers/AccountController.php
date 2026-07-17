<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function locale(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', 'in:de,en']]);
        $request->user()?->update(['locale' => $validated['locale']]);
        $request->session()->put('locale', $validated['locale']);

        return back();
    }

    public function activateCompany(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()?->belongsToCompany($company), 403);
        $request->session()->put('active_company_id', $company->getKey());

        return redirect()->route('dashboard');
    }

    public function readAllNotifications(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return back();
    }

    public function readNotification(Request $request, string $notification): RedirectResponse
    {
        $request->user()?->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }
}
