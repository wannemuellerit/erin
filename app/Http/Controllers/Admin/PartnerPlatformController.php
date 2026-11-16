<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CountryOperation;
use App\Enums\PartnerServiceType;
use App\Http\Controllers\Controller;
use App\Models\CountryServiceRule;
use App\Models\PartnerCase;
use App\Models\PartnerMember;
use App\Models\PartnerOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartnerPlatformController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Partners', [
            'organizations' => PartnerOrganization::query()->withCount(['members', 'offerings'])->latest()->get(),
            'rules' => CountryServiceRule::query()->latest('version')->orderBy('country_code')->get(),
            'cases' => PartnerCase::query()->selectRaw('service_type, status, count(*) as total')->groupBy('service_type', 'status')->get(),
            'serviceTypes' => PartnerServiceType::values(),
            'countryOperations' => CountryOperation::values(),
        ]);
    }

    public function storeOrganization(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'category' => ['required', 'string', Rule::in(PartnerServiceType::values())],
            'country_codes' => ['required', 'array', 'min:1'], 'country_codes.*' => ['string', 'size:2'],
            'service_types' => ['required', 'array', 'min:1'], 'service_types.*' => [Rule::enum(PartnerServiceType::class)],
            'languages' => ['nullable', 'array'], 'languages.*' => ['string', 'size:2'],
            'integration_mode' => ['required', Rule::in(['manual', 'api', 'sftp'])],
        ]);
        PartnerOrganization::query()->create([...$data, 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)), 'created_by' => $request->user()?->getKey()]);

        return back()->with('success', __('Partner wurde als nicht freigegebener Entwurf angelegt.'));
    }

    public function approveOrganization(Request $request, PartnerOrganization $organization): RedirectResponse
    {
        $data = $request->validate([
            'contract_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'dpa_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'legal_approved' => ['required', 'boolean'], 'logo_approved' => ['required', 'boolean'],
        ]);
        $organization->update([
            'contract_status' => $data['contract_status'], 'dpa_status' => $data['dpa_status'],
            'legal_approved_at' => $data['legal_approved'] ? now() : null,
            'logo_approved_at' => $data['logo_approved'] ? now() : null,
        ]);

        return back()->with('success', __('Partnerfreigaben aktualisiert.'));
    }

    public function blockOrganization(Request $request, PartnerOrganization $organization): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($organization, $data): void {
            $organization->update(['blocked_at' => now(), 'blocked_reason' => $data['reason']]);
            $organization->members()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            PartnerCase::query()->where('partner_organization_id', $organization->getKey())->whereNull('withdrawn_at')
                ->update(['status' => 'partner_blocked', 'public_status' => 'support_review', 'withdrawn_at' => now()]);
        });

        return back()->with('success', __('Partnerzugriff und aktive Datenfreigaben wurden sofort beendet.'));
    }

    public function storeMember(Request $request, PartnerOrganization $organization): RedirectResponse
    {
        abort_unless($organization->isOperational(), 422, __('Partner muss zuerst vollständig freigegeben sein.'));
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'partner')],
            'role' => ['required', Rule::in(['viewer', 'case_worker', 'admin'])],
            'capabilities' => ['required', 'array'], 'capabilities.*' => [Rule::in(['partner.cases.view', 'partner.cases.manage', 'partner.catalog.manage'])],
        ]);
        PartnerMember::query()->updateOrCreate(
            ['partner_organization_id' => $organization->getKey(), 'user_id' => $data['user_id']],
            ['role' => $data['role'], 'capabilities' => $data['capabilities'], 'accepted_at' => now(), 'revoked_at' => null],
        );

        return back()->with('success', __('Partnerrolle wurde zugeordnet.'));
    }

    public function storeOffering(Request $request, PartnerOrganization $organization): RedirectResponse
    {
        abort_unless($organization->isOperational(), 422, __('Partner muss zuerst vollständig freigegeben sein.'));
        $data = $request->validate([
            'service_type' => ['required', Rule::enum(PartnerServiceType::class)], 'country_code' => ['required', 'string', 'size:2'],
            'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'],
            'attributes' => ['nullable', 'array'], 'currency_code' => ['nullable', 'string', 'size:3'], 'price_minor' => ['nullable', 'integer', 'min:0'],
            'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', 'after:valid_from'], 'is_active' => ['required', 'boolean'],
        ]);
        abort_unless(in_array($data['service_type'], (array) $organization->service_types, true), 422);
        $version = (int) $organization->offerings()->where('service_type', $data['service_type'])->where('country_code', strtoupper($data['country_code']))->max('version') + 1;
        $organization->offerings()->create([...$data, 'country_code' => strtoupper($data['country_code']), 'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null, 'version' => $version]);

        return back()->with('success', __('Versioniertes Partnerangebot gespeichert.'));
    }

    public function storeCountryRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'], 'service_type' => ['required', Rule::enum(CountryOperation::class)],
            'status' => ['required', Rule::in(['disabled', 'pilot', 'enabled'])], 'currency_code' => ['nullable', 'string', 'size:3'],
            'tax_model' => ['nullable', 'string', 'max:160'], 'legal_basis' => ['required_if:status,pilot,enabled', 'nullable', 'string', 'max:500'],
            'requirements' => ['nullable', 'array'], 'approved' => ['required', 'boolean'],
        ]);
        $country = strtoupper($data['country_code']);
        $version = (int) CountryServiceRule::query()->where('country_code', $country)->where('service_type', $data['service_type'])->max('version') + 1;
        CountryServiceRule::query()->create([
            ...$data, 'country_code' => $country, 'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null,
            'version' => $version, 'approved_at' => $data['approved'] ? now() : null, 'approved_by' => $data['approved'] ? $request->user()?->getKey() : null,
        ]);

        return back()->with('success', __('Neue Version der Länderregel gespeichert.'));
    }
}
