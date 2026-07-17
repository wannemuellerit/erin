<?php

namespace App\Http\Controllers\Support;

use App\Enums\UserStatus;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Requests\Admin\StartImpersonationRequest;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends AdminController
{
    public function start(StartImpersonationRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($request->session()->has('impersonation_session_id')) {
            throw ValidationException::withMessages([
                'reason' => __('Beende zuerst die bereits aktive Supportansicht.'),
            ]);
        }

        if (
            $actor === null
            || $actor->is($user)
            || $user->isPlatformStaff()
            || $user->status !== UserStatus::Active
            || ! $user->hasVerifiedEmail()
        ) {
            throw ValidationException::withMessages([
                'reason' => __('Dieser Nutzer kann nicht in der Supportansicht geöffnet werden.'),
            ]);
        }

        $impersonation = ImpersonationSession::query()->create([
            'actor_id' => $actor->getKey(),
            'target_id' => $user->getKey(),
            'reason' => $request->validated('reason'),
            'mode' => 'read_only',
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'event' => 'support.impersonation.started',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->getKey(),
            'metadata' => [
                'impersonation_session_id' => $impersonation->getKey(),
                'mode' => 'read_only',
                'reason' => $impersonation->reason,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->session()->put([
            'impersonation_session_id' => $impersonation->getKey(),
            'impersonator_id' => $actor->getKey(),
            'impersonator_name' => $actor->name,
            'impersonation_target_id' => $user->getKey(),
            'impersonation_reason' => $impersonation->reason,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with(
            'warning',
            __('Du siehst Erin jetzt schreibgeschützt aus Sicht von :name.', ['name' => $user->name]),
        );
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonationId = $request->session()->get('impersonation_session_id');

        if (! is_numeric($impersonationId)) {
            abort(403, __('Es ist keine Supportansicht aktiv.'));
        }

        $impersonation = ImpersonationSession::query()
            ->with(['actor', 'target'])
            ->findOrFail((int) $impersonationId);

        if (
            $impersonation->ended_at !== null
            || $request->user()?->getKey() !== $impersonation->target_id
        ) {
            abort(403);
        }

        $impersonation->update(['ended_at' => now()]);

        AuditLog::query()->create([
            'actor_id' => $impersonation->actor_id,
            'event' => 'support.impersonation.ended',
            'auditable_type' => $impersonation->target->getMorphClass(),
            'auditable_id' => $impersonation->target_id,
            'metadata' => [
                'impersonation_session_id' => $impersonation->getKey(),
                'mode' => $impersonation->mode,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Auth::login($impersonation->actor);
        $request->session()->forget([
            'impersonation_session_id',
            'impersonator_id',
            'impersonator_name',
            'impersonation_target_id',
            'impersonation_reason',
        ]);
        $request->session()->regenerate();

        return redirect()->route('admin.support.index')->with(
            'success',
            __('Die Supportansicht wurde beendet.'),
        );
    }
}
