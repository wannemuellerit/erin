<?php

namespace App\Http\Controllers;

use App\Enums\ReferralStatus;
use App\Enums\UserRole;
use App\Models\Referral;
use App\Models\ReferralCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $code = $user->referralCodes()->where('is_active', true)->first();
        $referrals = $code?->referrals()->latest()->get() ?? collect();

        return Inertia::render(
            $user->role === UserRole::Company ? 'employer/Referrals' : 'candidate/Referrals',
            [
                'code' => $code ? [
                    ...$code->toArray(),
                    'url' => route('referrals.track', $code->code),
                ] : null,
                'metrics' => [
                    'clicks' => $referrals->count(),
                    'registrations' => $referrals->whereNotNull('registered_at')->count(),
                    'applications' => $referrals->whereIn('status', [
                        ReferralStatus::Applied,
                        ReferralStatus::Hired,
                        ReferralStatus::Holding,
                        ReferralStatus::Approved,
                        ReferralStatus::Paid,
                    ])->count(),
                    'placements' => $referrals->whereIn('status', [
                        ReferralStatus::Hired,
                        ReferralStatus::Holding,
                        ReferralStatus::Approved,
                        ReferralStatus::Paid,
                    ])->count(),
                    'approved_cents' => $referrals->whereIn('status', [
                        ReferralStatus::Approved,
                        ReferralStatus::Paid,
                    ])->sum('commission_cents'),
                    'paid_cents' => $referrals->where('status', ReferralStatus::Paid)->sum('commission_cents'),
                ],
                'referrals' => $referrals,
            ],
        );
    }

    public function create(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $user->referralCodes()->firstOrCreate(
            ['is_active' => true],
            [
                'code' => $this->uniqueCode(),
                'commission_cents' => 0,
                'currency' => 'EUR',
            ],
        );

        return back()->with('success', __('Dein persönlicher Empfehlungslink ist bereit.'));
    }

    public function email(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $code = $user->referralCodes()->where('is_active', true)->firstOrFail();
        $link = route('referrals.track', $code->code);

        Mail::raw(
            ($validated['message'] ?? __('Ich möchte dir Erin empfehlen.'))."\n\n".$link,
            fn ($message) => $message
                ->to($validated['email'])
                ->subject(__('Einladung zu Erin')),
        );

        return back()->with('success', __('Die Empfehlung wurde per E-Mail versendet.'));
    }

    public function track(Request $request, string $code): RedirectResponse
    {
        $referralCode = ReferralCode::query()->where('code', $code)->where('is_active', true)->firstOrFail();
        $cookieToken = $request->cookie('erin_referral_visitor');
        $visitorToken = is_string($cookieToken) && $cookieToken !== ''
            ? $cookieToken
            : (string) Str::uuid();

        Referral::query()->firstOrCreate(
            [
                'referral_code_id' => $referralCode->getKey(),
                'visitor_token' => $visitorToken,
            ],
            [
                'status' => ReferralStatus::Clicked,
                'clicked_at' => now(),
                'commission_cents' => $referralCode->commission_cents,
                'currency' => $referralCode->currency,
                'metadata' => [
                    'utm_source' => $request->string('utm_source')->toString() ?: null,
                ],
            ],
        );

        return redirect()->route('register')
            ->withCookie(cookie(
                'erin_referral_visitor',
                $visitorToken,
                60 * 24 * 30,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (ReferralCode::query()->where('code', $code)->exists());

        return $code;
    }
}
