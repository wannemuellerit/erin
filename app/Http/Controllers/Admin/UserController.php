<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Admin\StoreCandidateRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Requests\Admin\UpdateUserStorageQuotaRequest;
use App\Models\Occupation;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\Documents\UploadPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
                'platform_role_id',
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
                'platformRole:id,name',
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
            'platform_roles' => PlatformRole::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'occupations' => Occupation::query()->where('is_active', true)
                ->orderBy('name_de')->get(['id', 'name_de', 'name_en']),
        ]);
    }

    public function storeCandidate(StoreCandidateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = DB::transaction(function () use ($validated): User {
            $user = User::query()->create([
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => mb_strtolower($validated['email']),
                'password' => $validated['temporary_password'],
                'role' => UserRole::Candidate,
                'status' => UserStatus::Active,
                'locale' => $validated['locale'],
                'timezone' => 'Europe/Berlin',
                'password_change_required_at' => now(),
            ]);
            if ($validated['email_verified'] ?? false) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->candidateProfile()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'current_country_code' => isset($validated['current_country_code'])
                    ? mb_strtoupper($validated['current_country_code'])
                    : null,
                'current_city' => $validated['current_city'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'occupation_id' => $validated['occupation_id'] ?? null,
                'current_position' => $validated['current_position'] ?? null,
                'desired_position' => $validated['desired_position'] ?? null,
                'summary' => $validated['summary'] ?? null,
                'salary_currency' => 'EUR',
                'experience_years' => 0,
            ]);

            return $user;
        });

        $this->audit($request, 'admin.candidate_created', $user, after: [
            'role' => UserRole::Candidate->value,
            'email_verified' => $user->email_verified_at !== null,
            'requires_password_change' => true,
        ]);

        return back()->with('success', __('Die Fachkraft wurde angelegt und muss beim ersten Login das Passwort ändern.'));
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

        $user->update([
            'role' => $nextRole,
            'platform_role_id' => $nextRole === UserRole::Support ? $user->platform_role_id : null,
        ]);

        $this->audit(
            $request,
            'admin.user.role_updated',
            $user,
            $before,
            ['role' => $user->role->value],
        );

        return back()->with('success', __('Die Plattformrolle wurde aktualisiert.'));
    }

    public function updatePlatformRole(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        abort_unless($user->role === UserRole::Support, 422, __('Zusatzrollen können nur Supportkonten zugeordnet werden.'));
        $validated = $request->validate([
            'platform_role_id' => [
                'nullable',
                Rule::exists('platform_roles', 'id')->where('is_active', true),
            ],
        ]);
        $before = ['platform_role_id' => $user->platform_role_id];
        $user->update(['platform_role_id' => $validated['platform_role_id'] ?? null]);
        $this->audit($request, 'admin.user.platform_role_updated', $user, $before, [
            'platform_role_id' => $user->platform_role_id,
        ]);

        return back()->with('success', __('Die zusätzliche Plattformrolle wurde aktualisiert.'));
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
