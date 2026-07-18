<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminBootstrapInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AdminBootstrapController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->invitation($token);

        return Inertia::render('auth/AdminBootstrap', [
            'email' => $invitation->email,
            'token' => $token,
            'submitUrl' => route('admin-bootstrap.store', ['token' => $token]),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $audit,
        string $token,
    ): RedirectResponse {
        $validated = $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(16)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ]);

        $user = DB::transaction(function () use ($token, $validated, $audit): User {
            $invitation = AdminBootstrapInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            abort_if(
                $invitation === null
                    || $invitation->used_at !== null
                    || ! $invitation->expires_at->isFuture(),
                410,
                __('Diese Admin-Einladung ist abgelaufen oder wurde bereits verwendet.'),
            );

            $user = User::query()
                ->where('email', $invitation->email)
                ->lockForUpdate()
                ->first();

            abort_if(
                $user !== null
                    && ! $user->isSuperAdmin()
                    && ! $invitation->allow_role_change,
                409,
                __('Für diese E-Mail existiert bereits eine andere Rolle.'),
            );

            $user ??= new User;
            $user->forceFill([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => $validated['password'],
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
                'password_change_required_at' => now(),
            ])->save();

            $invitation->forceFill(['used_at' => now()])->save();

            $audit->record(
                'admin.bootstrap.accepted',
                $user,
                after: [
                    'role' => UserRole::SuperAdmin->value,
                    'password_change_required' => true,
                    'two_factor_required' => true,
                ],
                metadata: ['invitation_id' => $invitation->getKey()],
            );

            return $user;
        }, 3);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('security.edit')->with(
            'warning',
            __('Ändere jetzt dein Passwort erneut und richte anschließend Zwei-Faktor-Authentifizierung ein.'),
        );
    }

    private function invitation(string $token): AdminBootstrapInvitation
    {
        $invitation = AdminBootstrapInvitation::query()
            ->usable()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_if(
            $invitation === null,
            410,
            __('Diese Admin-Einladung ist abgelaufen oder wurde bereits verwendet.'),
        );

        return $invitation;
    }
}
