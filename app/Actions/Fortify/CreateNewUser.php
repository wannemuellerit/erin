<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\ReferralStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\User;
use App\Services\Access\AccessListResolver;
use App\Services\Billing\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AccessListResolver $accessList,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $access = $this->accessList->decide(
            $input['email'] ?? null,
            request()->ip(),
        );
        if ($access->blocked()) {
            throw ValidationException::withMessages([
                'email' => __('Die Registrierung konnte nicht abgeschlossen werden. Bitte prüfe deine Angaben oder kontaktiere den Support.'),
            ]);
        }

        $invitationToken = request()->cookie('erin_company_invitation');
        /** @var CompanyInvitation|null $invitation */
        $invitation = is_string($invitationToken)
            ? CompanyInvitation::query()
                ->with('company.plan')
                ->where('token', $invitationToken)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        if ($invitation && mb_strtolower($invitation->email) === mb_strtolower($input['email'] ?? '')) {
            $input['role'] = UserRole::Company->value;
            $input['company_name'] = $invitation->company->name;
        }

        $input['role'] ??= UserRole::Candidate->value;

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'role' => ['required', Rule::enum(UserRole::class)->only([
                UserRole::Candidate,
                UserRole::Company,
            ])],
            'company_name' => ['nullable', 'required_if:role,'.UserRole::Company->value, 'string', 'max:160'],
            'plan_slug' => [
                'nullable',
                Rule::exists('plans', 'slug')->where(
                    fn ($query) => $query->where('is_active', true)->where('is_enterprise', false),
                ),
            ],
        ])->validate();

        return DB::transaction(function () use ($input, $invitation): User {
            $role = UserRole::from($input['role']);
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => $role,
                'status' => UserStatus::Pending,
                'locale' => $input['locale'] ?? 'de',
                'onboarding_completed_at' => $invitation !== null ? now() : null,
            ]);

            if ($role === UserRole::Company) {
                if ($invitation !== null) {
                    /** @var Company $company */
                    $company = Company::query()
                        ->with('plan')
                        ->lockForUpdate()
                        ->findOrFail($invitation->company_id);
                    try {
                        $this->entitlements->assertCanAddSeat($company);
                    } catch (DomainException $exception) {
                        abort(422, $exception->getMessage());
                    }
                    $company->memberships()->create([
                        'user_id' => $user->getKey(),
                        'role' => $invitation->role,
                        'invited_by' => $invitation->invited_by,
                        'accepted_at' => now(),
                    ]);
                    $invitation->update(['accepted_at' => now()]);
                } else {
                    $selectedPlan = isset($input['plan_slug'])
                        ? Plan::query()->where('slug', $input['plan_slug'])->first()
                        : null;
                    $company = Company::query()->create([
                        'name' => $input['company_name'],
                        'slug' => $this->uniqueCompanySlug($input['company_name']),
                        'email' => $input['email'],
                        'status' => CompanyStatus::Pending,
                        'current_plan_id' => $selectedPlan?->getKey(),
                    ]);

                    $company->memberships()->create([
                        'user_id' => $user->getKey(),
                        'role' => CompanyMemberRole::Owner,
                        'accepted_at' => now(),
                    ]);
                }
            } else {
                [$firstName, $lastName] = $this->splitName($input['name']);

                CandidateProfile::query()->create([
                    'user_id' => $user->getKey(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
            }

            $visitorToken = request()->cookie('erin_referral_visitor');
            if (is_string($visitorToken) && $visitorToken !== '') {
                Referral::query()
                    ->where('visitor_token', $visitorToken)
                    ->whereNull('referred_user_id')
                    ->latest('clicked_at')
                    ->first()
                    ?->update([
                        'referred_user_id' => $user->getKey(),
                        'status' => ReferralStatus::Registered,
                        'registered_at' => now(),
                        'email_hash' => hash('sha256', mb_strtolower($user->email)),
                    ]);
            }

            return $user;
        });
    }

    private function uniqueCompanySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'unternehmen';
        $slug = $base;
        $suffix = 2;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array{string, string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? $name,
            $parts[1] ?? '—',
        ];
    }
}
