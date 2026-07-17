<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CompanyStatus;
use App\Http\Requests\Admin\UpdateCompanyStatusRequest;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
            'subscription_status' => ['nullable', 'string', 'max:40'],
            'plan' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:newest,last_active,name'],
        ]);

        $companies = Company::query()
            ->select([
                'id',
                'current_plan_id',
                'name',
                'slug',
                'legal_name',
                'email',
                'industry',
                'employee_count',
                'country_code',
                'city',
                'status',
                'subscription_status',
                'subscription_renews_at',
                'last_active_at',
                'created_at',
            ])
            ->with('plan:id,slug,name,price_cents,currency')
            ->withCount([
                'memberships',
                'jobPostings',
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('legal_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(
                isset($filters['status']) && CompanyStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['subscription_status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('subscription_status', $status),
            )
            ->when(
                $filters['plan'] ?? null,
                fn (Builder $query, string $plan): Builder => $query->whereHas(
                    'plan',
                    fn (Builder $query): Builder => $query->where('slug', $plan),
                ),
            )
            ->when(
                ($filters['sort'] ?? 'newest') === 'last_active',
                fn (Builder $query): Builder => $query->orderByDesc('last_active_at'),
                fn (Builder $query): Builder => ($filters['sort'] ?? null) === 'name'
                    ? $query->orderBy('name')
                    : $query->latest(),
            )
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Companies', [
            'companies' => $companies,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (CompanyStatus $status): string => $status->value,
                CompanyStatus::cases(),
            ),
        ]);
    }

    public function updateStatus(UpdateCompanyStatusRequest $request, Company $company): RedirectResponse
    {
        $validated = $request->validated();
        $before = ['status' => $company->status->value];

        $company->update(['status' => CompanyStatus::from($validated['status'])]);

        $this->audit(
            $request,
            'admin.company.status_updated',
            $company,
            $before,
            ['status' => $company->status->value],
            ['reason' => $validated['reason'] ?? null],
        );

        return back()->with('success', __('Der Firmenstatus wurde aktualisiert.'));
    }
}
