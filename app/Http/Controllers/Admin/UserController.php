<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Requests\Admin\UpdateUserStorageQuotaRequest;
use App\Models\User;
use App\Services\Documents\UploadPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends AdminController
{
    public function index(Request $request, UploadPolicy $uploadPolicy): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'sort' => ['nullable', 'in:newest,oldest,last_active'],
        ]);

        $users = User::query()
            ->select([
                'id',
                'name',
                'email',
                'email_verified_at',
                'role',
                'status',
                'locale',
                'last_active_at',
                'storage_quota_bytes',
                'suspended_at',
                'blocked_reason',
                'created_at',
            ])
            ->with([
                'candidateProfile:id,user_id,current_position,desired_position,current_country_code,completeness,published_at',
            ])
            ->withCount('companyMemberships')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(
                isset($filters['role']) && UserRole::tryFrom($filters['role']) !== null,
                fn (Builder $query): Builder => $query->where('role', $filters['role']),
            )
            ->when(
                isset($filters['status']) && UserStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['sort'] ?? 'newest') === 'oldest',
                fn (Builder $query): Builder => $query->oldest(),
                fn (Builder $query): Builder => ($filters['sort'] ?? null) === 'last_active'
                    ? $query->orderByDesc('last_active_at')
                    : $query->latest(),
            )
            ->paginate(20)
            ->withQueryString();

        $usage = $uploadPolicy->usageForUsers($users->getCollection());
        $users->getCollection()->each(function (User $user) use ($usage): void {
            $user->setAttribute('storage_usage', $usage[$user->getKey()]);
        });

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => $filters,
            'roles' => array_map(
                static fn (UserRole $role): string => $role->value,
                UserRole::cases(),
            ),
            'statuses' => array_map(
                static fn (UserStatus $status): string => $status->value,
                UserStatus::cases(),
            ),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $nextStatus = UserStatus::from($validated['status']);

        if ($request->user()?->is($user)) {
            throw ValidationException::withMessages([
                'status' => __('Du kannst deinen eigenen Plattformzugang nicht sperren.'),
            ]);
        }

        $this->guardLastSuperAdmin($user, $nextStatus, $user->role);

        $before = [
            'status' => $user->status->value,
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'blocked_reason' => $user->blocked_reason,
        ];

        $user->forceFill([
            'status' => $nextStatus,
            'suspended_at' => in_array($nextStatus, [UserStatus::Suspended, UserStatus::Blocked], true)
                ? now()
                : null,
            'blocked_reason' => in_array($nextStatus, [UserStatus::Suspended, UserStatus::Blocked], true)
                ? $validated['reason']
                : null,
        ])->save();

        $this->audit(
            $request,
            'admin.user.status_updated',
            $user,
            $before,
            [
                'status' => $user->status->value,
                'suspended_at' => $user->suspended_at?->toIso8601String(),
                'blocked_reason' => $user->blocked_reason,
            ],
        );

        return back()->with('success', __('Der Nutzerstatus wurde aktualisiert.'));
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $nextRole = UserRole::from($request->validated('role'));

        if ($request->user()?->is($user)) {
            throw ValidationException::withMessages([
                'role' => __('Du kannst deine eigene Plattformrolle nicht ändern.'),
            ]);
        }

        $this->guardLastSuperAdmin($user, $user->status, $nextRole);
        $before = ['role' => $user->role->value];

        $user->update(['role' => $nextRole]);

        $this->audit(
            $request,
            'admin.user.role_updated',
            $user,
            $before,
            ['role' => $user->role->value],
        );

        return back()->with('success', __('Die Plattformrolle wurde aktualisiert.'));
    }

    public function updateStorageQuota(
        UpdateUserStorageQuotaRequest $request,
        User $user,
    ): RedirectResponse {
        $before = ['storage_quota_bytes' => $user->storage_quota_bytes];
        $megabytes = $request->validated('storage_quota_mb');
        $user->update([
            'storage_quota_bytes' => $megabytes === null
                ? null
                : (int) $megabytes * 1024 * 1024,
        ]);

        $this->audit(
            $request,
            'admin.user.storage_quota_updated',
            $user,
            $before,
            ['storage_quota_bytes' => $user->storage_quota_bytes],
        );

        return back()->with('success', __('Das persönliche Speicherlimit wurde aktualisiert.'));
    }

    private function guardLastSuperAdmin(User $user, UserStatus $nextStatus, UserRole $nextRole): void
    {
        if (
            $user->role === UserRole::SuperAdmin
            && ($nextRole !== UserRole::SuperAdmin || $nextStatus !== UserStatus::Active)
            && User::query()
                ->where('role', UserRole::SuperAdmin)
                ->where('status', UserStatus::Active)
                ->count() <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => __('Der letzte aktive Superadmin kann nicht entfernt oder gesperrt werden.'),
            ]);
        }
    }
}
