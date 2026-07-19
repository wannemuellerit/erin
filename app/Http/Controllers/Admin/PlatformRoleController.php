<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Capability;
use App\Models\PlatformRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformRoleController extends AdminController
{
    private const ALLOWED = [
        Capability::DashboardView->value,
        Capability::PlatformView->value,
        Capability::PlatformSupportManage->value,
        Capability::SupportUse->value,
    ];

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $role = PlatformRole::query()->create([
            ...$validated,
            'created_by' => $request->user()?->getKey(),
        ]);
        $this->audit($request, 'admin.platform_role.created', $role, [], $role->toArray());

        return back()->with('success', __('Die Plattformrolle wurde erstellt.'));
    }

    public function update(Request $request, PlatformRole $platformRole): RedirectResponse
    {
        $before = $platformRole->toArray();
        $platformRole->update($this->validated($request, $platformRole));
        $this->audit($request, 'admin.platform_role.updated', $platformRole, $before, $platformRole->toArray());

        return back()->with('success', __('Die Plattformrolle wurde aktualisiert.'));
    }

    public function destroy(Request $request, PlatformRole $platformRole): RedirectResponse
    {
        if ($platformRole->users()->exists()) {
            throw ValidationException::withMessages([
                'platform_role' => __('Die Rolle ist noch Nutzern zugewiesen und kann nicht gelöscht werden.'),
            ]);
        }
        $before = $platformRole->toArray();
        $platformRole->delete();
        $this->audit($request, 'admin.platform_role.deleted', $platformRole, $before);

        return back()->with('success', __('Die Plattformrolle wurde gelöscht.'));
    }

    /**
     * @return array{name: string, capabilities: list<string>, is_active: bool}
     */
    private function validated(Request $request, ?PlatformRole $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('platform_roles')->ignore($role)],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', Rule::in(self::ALLOWED)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
