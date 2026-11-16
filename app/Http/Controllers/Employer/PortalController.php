<?php

namespace App\Http\Controllers\Employer;

use App\Enums\Capability;
use App\Enums\CompanyMemberRole;
use App\Enums\CountryOperation;
use App\Enums\VisaCaseStatus;
use App\Enums\VisaStepStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ScanCompanyMedia;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMedia;
use App\Models\CompanyMembership;
use App\Models\CompanyTeam;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruiterReminder;
use App\Models\User;
use App\Models\VisaCase;
use App\Models\VisaStep;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CapabilityResolver;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use App\Services\Countries\CountryLaunchGate;
use App\Services\Documents\UploadPolicy;
use App\Services\Platform\ProductNotificationDispatcher;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PortalController extends Controller
{
    public function companyProfile(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = $currentCompany->forRequest($request);
        $company->load(['locations', 'media']);

        return Inertia::render('employer/CompanyProfile', [
            'company' => [
                ...Arr::except($company->toArray(), ['media', 'logo_path']),
                'media' => $company->media->map(
                    fn (CompanyMedia $media): array => $this->serializeMedia($media),
                )->values(),
            ],
            'benefit_options' => [
                'accommodation',
                'german_course',
                'visa_support',
                'canteen',
                'work_clothing',
                'company_vehicle',
            ],
        ]);
    }

    public function updateCompanyProfile(
        Request $request,
        CurrentCompany $currentCompany,
        UploadPolicy $uploads,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        // Multipart submissions omit empty arrays; distinguish clearing the
        // location editor from clients that do not submit locations at all.
        if ($request->boolean('locations_submitted')) {
            $request->merge(['locations' => $request->input('locations', [])]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'industry' => ['required', 'string', 'max:120'],
            'employee_count' => ['nullable', 'integer', 'min:1'],
            'country_code' => ['required', 'string', 'size:2'],
            'registered_country_code' => ['required', 'string', 'size:2'],
            'default_target_country_code' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:10000'],
            'benefits' => ['array'],
            'benefits.accommodation' => ['boolean'],
            'benefits.german_course' => ['boolean'],
            'benefits.visa_support' => ['boolean'],
            'benefits.canteen' => ['boolean'],
            'benefits.work_clothing' => ['boolean'],
            'benefits.company_vehicle' => ['boolean'],
            'locations' => ['array', 'max:30'],
            'locations.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('company_locations', 'id')->where('company_id', $company->getKey())],
            'locations.*.name' => ['required', 'string', 'max:120'],
            'locations.*.country_code' => ['required', 'string', 'size:2'],
            'locations.*.city' => ['required', 'string', 'max:120'],
            'locations.*.postal_code' => ['nullable', 'string', 'max:20'],
            'locations.*.address_line1' => ['nullable', 'string', 'max:180'],
            'locations.*.is_headquarters' => ['boolean'],
            'logo' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,gif,webp',
                'max:'.$uploads->maxFileKilobytes(5120),
            ],
            'media' => ['array', 'max:12'],
            'media.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf',
                'max:'.$uploads->maxFileKilobytes(51200),
            ],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $files = array_values(array_filter([
            $request->file('logo'),
            ...$request->file('media', []),
        ]));
        if ($files !== []) {
            $uploads->assertCanStore($user, $files, 'media');
        }

        DB::transaction(function () use ($request, $company, $validated): void {
            $company->update(Arr::except($validated, ['locations', 'logo', 'media']));
            if (array_key_exists('locations', $validated)) {
                $retainedIds = [];
                foreach ($validated['locations'] as $location) {
                    $attributes = Arr::except($location, ['id']);
                    if (filled($location['id'] ?? null)) {
                        $record = $company->locations()->whereKey($location['id'])->firstOrFail();
                        $record->update($attributes);
                    } else {
                        $record = $company->locations()->create($attributes);
                    }
                    $retainedIds[] = $record->getKey();
                }
                $company->locations()->whereNotIn('id', $retainedIds)->delete();
            }

            $logo = $request->file('logo');
            if ($logo !== null) {
                $media = $this->storeCompanyMedia($company, $request, $logo, 'logo');
                $company->update(['logo_media_id' => $media->getKey(), 'logo_path' => null]);
            }

            foreach ($request->file('media', []) as $file) {
                $mime = (string) $file->getMimeType();
                $type = str_starts_with($mime, 'video/')
                    ? 'video'
                    : (str_starts_with($mime, 'image/') ? 'image' : 'document');
                $this->storeCompanyMedia($company, $request, $file, $type);
            }
        });

        return back()->with('success', __('Das Firmenprofil wurde gespeichert.'));
    }

    public function team(
        Request $request,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
    ): Response {
        $company = $currentCompany->forRequest($request);

        return Inertia::render('employer/Team', [
            'members' => $company->memberships()->with([
                'user:id,name,email,last_active_at',
                'location:id,name,city',
                'teams:id,name',
            ])->get(),
            'invitations' => $company->invitations()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->latest()
                ->get()
                ->each->append('status'),
            'teams' => $company->teams()->with([
                'memberships.user:id,name,email',
                'location:id,name,city',
                'contactMembership.user:id,name,email',
            ])->orderBy('name')->get(),
            'locations' => $company->locations()->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name', 'city']),
            'jobs' => $company->jobPostings()->orderBy('title')->get(['id', 'title', 'company_team_id', 'contact_membership_id']),
            'applications' => JobApplication::query()
                ->whereHas('jobPosting', fn ($jobs) => $jobs->where('company_id', $company->getKey()))
                ->with(['jobPosting:id,title', 'candidateProfile:id,first_name,last_name'])
                ->latest('applied_at')
                ->limit(100)
                ->get(['id', 'job_posting_id', 'candidate_profile_id', 'company_team_id', 'contact_membership_id'])
                ->map(fn (JobApplication $application): array => [
                    'id' => $application->getKey(),
                    'label' => $application->candidateProfile->anonymizedLabel().' · '.$application->jobPosting->title,
                    'company_team_id' => $application->company_team_id,
                    'contact_membership_id' => $application->contact_membership_id,
                ])->values(),
            'seats' => $entitlements->summary($company)['seats'],
            'can_manage' => in_array(
                Capability::TeamManage->value,
                app(CapabilityResolver::class)->forRequest($request),
                true,
            ),
            'can_transfer_ownership' => in_array(
                Capability::OwnershipTransfer->value,
                app(CapabilityResolver::class)->forRequest($request),
                true,
            ),
        ]);
    }

    public function inviteTeamMember(
        Request $request,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(CompanyMemberRole::class)->only([
                CompanyMemberRole::Admin,
                CompanyMemberRole::Viewer,
            ])],
        ]);

        $email = mb_strtolower($validated['email']);
        if ($company->users()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return back()->withErrors(['email' => __('Diese Person ist bereits Mitglied des Unternehmens.')]);
        }

        try {
            [$invitation, $created] = DB::transaction(function () use (
                $company,
                $request,
                $entitlements,
                $validated,
                $email,
                $audit,
            ): array {
                /** @var Company $lockedCompany */
                $lockedCompany = Company::query()->with('plan')->lockForUpdate()->findOrFail($company->getKey());
                $existing = $lockedCompany->invitations()
                    ->where('email', $email)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null
                    && $existing->accepted_at === null
                    && $existing->revoked_at === null
                    && $existing->expires_at->isFuture()) {
                    abort_unless($existing->role->value === $validated['role'], 422, __('Für diese Adresse besteht bereits eine Einladung mit einer anderen Rolle.'));

                    return [$existing, false];
                }

                $this->assertCanReserveSeat($lockedCompany, $entitlements, $existing?->getKey());
                $before = $existing?->only(['role', 'expires_at', 'revoked_at']) ?? [];
                $invitation = $lockedCompany->invitations()->updateOrCreate(
                    ['email' => $email],
                    [
                        'invited_by' => $request->user()?->getKey(),
                        'role' => $validated['role'],
                        'token' => hash('sha256', Str::random(64)),
                        'expires_at' => now()->addDays(7),
                        'accepted_at' => null,
                        'revoked_at' => null,
                    ],
                );
                $audit->record(
                    'company.invitation_created',
                    $invitation,
                    before: $before,
                    after: $invitation->only(['role', 'expires_at', 'revoked_at']),
                    companyId: (int) $lockedCompany->getKey(),
                );

                return [$invitation, true];
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        if ($created) {
            $this->sendCompanyInvitation($company, $invitation);
        }

        return back()->with('success', $created
            ? __('Die Teameinladung wurde versendet.')
            : __('Die bestehende Teameinladung ist weiterhin gültig.'));
    }

    public function trackInvitation(Request $request, string $token): RedirectResponse
    {
        /** @var CompanyInvitation $invitation */
        $invitation = CompanyInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        if ($request->user()) {
            return redirect()->route('company-invitations.accept', $token);
        }

        return redirect()->route('register')
            ->withCookie(cookie(
                'erin_company_invitation',
                $token,
                60 * 24 * 7,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
    }

    public function acceptInvitation(
        Request $request,
        string $token,
        EntitlementService $entitlements,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        /** @var CompanyInvitation $invitation */
        $invitation = CompanyInvitation::query()
            ->with('company')
            ->where('token', $token)
            ->firstOrFail();
        if (! hash_equals(mb_strtolower($invitation->email), mb_strtolower($user->email))) {
            abort(403);
        }
        if ($invitation->accepted_at !== null
            && $invitation->company->memberships()->where('user_id', $user->getKey())->whereNotNull('accepted_at')->exists()) {
            $request->session()->put('active_company_id', $invitation->company_id);

            return redirect()->route('dashboard')->with('success', __('Du bist bereits Mitglied dieses Firmenteams.'));
        }
        abort_unless(
            $invitation->revoked_at === null && $invitation->expires_at->isFuture(),
            422,
            __('Diese Einladung ist nicht mehr gültig.'),
        );

        try {
            DB::transaction(function () use ($invitation, $user, $entitlements, $audit): void {
                $company = $invitation->company()->with('plan')->lockForUpdate()->firstOrFail();
                $lockedInvitation = CompanyInvitation::query()
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless(
                    $lockedInvitation->accepted_at === null
                    && $lockedInvitation->revoked_at === null
                    && $lockedInvitation->expires_at->isFuture(),
                    422,
                    __('Diese Einladung ist nicht mehr gültig.'),
                );
                $entitlements->assertCanAddSeat($company);

                $company->memberships()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    [
                        'role' => $lockedInvitation->role,
                        'invited_by' => $lockedInvitation->invited_by,
                        'accepted_at' => now(),
                    ],
                );
                $lockedInvitation->update(['accepted_at' => now()]);
                $audit->record('company.invitation_accepted', $lockedInvitation, before: [
                    'accepted_at' => null,
                    'member_user_id' => null,
                ], after: [
                    'accepted_at' => $lockedInvitation->accepted_at,
                    'member_user_id' => $user->getKey(),
                    'role' => $lockedInvitation->role->value,
                ], companyId: (int) $company->getKey());
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['invitation' => $exception->getMessage()]);
        }

        $request->session()->put('active_company_id', $invitation->company_id);

        foreach ($invitation->company->users()->wherePivotNotNull('accepted_at')->whereKeyNot($user->getKey())->get() as $member) {
            app(ProductNotificationDispatcher::class)->dispatch(
                $member,
                'company.invitation_accepted',
                "company-invitation:{$invitation->getKey()}:accepted",
                [
                    'title' => __('Teameinladung angenommen'),
                    'message' => __('Eine eingeladene Person ist dem Firmenteam beigetreten.'),
                    'translations' => [
                        'de' => ['title' => 'Teameinladung angenommen', 'message' => 'Eine eingeladene Person ist dem Firmenteam beigetreten.'],
                        'en' => ['title' => 'Team invitation accepted', 'message' => 'An invited person joined the company team.'],
                    ],
                    'url' => route('employer.team', absolute: false),
                    'company_id' => $invitation->company_id,
                ],
            );
        }

        return redirect()->route('dashboard')->with('success', __('Du bist dem Firmenteam beigetreten.'));
    }

    public function resendTeamInvitation(
        Request $request,
        CompanyInvitation $invitation,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($invitation->company_id === $company->getKey(), 404);
        abort_if($invitation->accepted_at !== null, 422, __('Eine angenommene Einladung kann nicht erneut gesendet werden.'));

        try {
            $invitation = DB::transaction(function () use ($company, $invitation, $request, $entitlements, $audit): CompanyInvitation {
                /** @var Company $lockedCompany */
                $lockedCompany = Company::query()->with('plan')->lockForUpdate()->findOrFail($company->getKey());
                /** @var CompanyInvitation $locked */
                $locked = CompanyInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
                $before = $locked->only(['role', 'expires_at', 'revoked_at']);
                if ($locked->revoked_at !== null || $locked->expires_at->isPast()) {
                    $this->assertCanReserveSeat($lockedCompany, $entitlements, $locked->getKey());
                    $locked->token = hash('sha256', Str::random(64));
                }
                $locked->forceFill([
                    'invited_by' => $request->user()?->getKey(),
                    'expires_at' => now()->addDays(7),
                    'revoked_at' => null,
                ])->save();
                $audit->record(
                    'company.invitation_resent',
                    $locked,
                    before: $before,
                    after: $locked->only(['role', 'expires_at', 'revoked_at']),
                    companyId: (int) $company->getKey(),
                );

                return $locked;
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['invitation' => $exception->getMessage()]);
        }

        $this->sendCompanyInvitation($company, $invitation);

        return back()->with('success', __('Die Teameinladung wurde erneut versendet.'));
    }

    public function revokeTeamInvitation(
        Request $request,
        CompanyInvitation $invitation,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($invitation->company_id === $company->getKey(), 404);
        abort_if($invitation->accepted_at !== null, 422, __('Eine angenommene Einladung kann nicht widerrufen werden.'));

        if ($invitation->revoked_at === null) {
            $before = $invitation->only(['role', 'expires_at', 'revoked_at']);
            $invitation->update(['revoked_at' => now()]);
            $audit->record(
                'company.invitation_revoked',
                $invitation,
                before: $before,
                after: $invitation->only(['role', 'expires_at', 'revoked_at']),
                companyId: (int) $company->getKey(),
            );
        }

        return back()->with('success', __('Die Teameinladung wurde widerrufen.'));
    }

    public function updateTeamMember(
        Request $request,
        CompanyMembership $membership,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($membership->company_id === $company->getKey(), 404);
        abort_if($membership->role === CompanyMemberRole::Owner, 422, __('Die Owner-Rolle wird ausschließlich über den sicheren Transfer geändert.'));
        $validated = $request->validate([
            'role' => ['sometimes', 'required', Rule::enum(CompanyMemberRole::class)->only([
                CompanyMemberRole::Admin,
                CompanyMemberRole::Viewer,
            ])],
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('company_locations', 'id')->where('company_id', $company->getKey())],
            'team_ids' => ['sometimes', 'array', 'max:30'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('company_teams', 'id')->where('company_id', $company->getKey())],
        ]);
        abort_if($validated === [], 422);
        $before = [
            'role' => $membership->role->value,
            'location_id' => $membership->location_id,
            'team_ids' => $membership->teams()->orderBy('company_teams.id')->pluck('company_teams.id')->all(),
        ];

        DB::transaction(function () use ($membership, $validated): void {
            $membership->update(Arr::only($validated, ['role', 'location_id']));
            if (array_key_exists('team_ids', $validated)) {
                $membership->teams()->sync($validated['team_ids']);
            }
        });
        $membership->refresh();
        $audit->record('company.member_updated', $membership, before: $before, after: [
            'role' => $membership->role->value,
            'location_id' => $membership->location_id,
            'team_ids' => $membership->teams()->orderBy('company_teams.id')->pluck('company_teams.id')->all(),
        ], companyId: (int) $company->getKey());

        return back()->with('success', __('Das Teammitglied wurde aktualisiert.'));
    }

    public function storeTeam(
        Request $request,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        $validated = $this->validateTeam($request, $company);
        $team = DB::transaction(function () use ($company, $validated): CompanyTeam {
            $team = $company->teams()->create(Arr::only($validated, ['name', 'location_id', 'contact_membership_id']));
            $memberIds = $validated['membership_ids'] ?? [];
            if (isset($validated['contact_membership_id'])) {
                $memberIds[] = $validated['contact_membership_id'];
            }
            $team->memberships()->sync(array_values(array_unique($memberIds)));

            return $team;
        });
        $audit->record('company.team_created', $team, after: $this->teamAuditValues($team), companyId: (int) $company->getKey());

        return back()->with('success', __('Das Team wurde erstellt.'));
    }

    public function updateTeam(
        Request $request,
        CompanyTeam $team,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($team->company_id === $company->getKey(), 404);
        $before = $this->teamAuditValues($team);
        $validated = $this->validateTeam($request, $company, $team);
        DB::transaction(function () use ($team, $validated): void {
            $team->update(Arr::only($validated, ['name', 'location_id', 'contact_membership_id']));
            $memberIds = $validated['membership_ids'] ?? [];
            if (isset($validated['contact_membership_id'])) {
                $memberIds[] = $validated['contact_membership_id'];
            }
            $team->memberships()->sync(array_values(array_unique($memberIds)));
        });
        $audit->record('company.team_updated', $team, before: $before, after: $this->teamAuditValues($team), companyId: (int) $company->getKey());

        return back()->with('success', __('Das Team wurde aktualisiert.'));
    }

    public function destroyTeam(
        Request $request,
        CompanyTeam $team,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($team->company_id === $company->getKey(), 404);
        $before = $this->teamAuditValues($team);
        $audit->record('company.team_deleted', $team, before: $before, after: ['deleted' => true], companyId: (int) $company->getKey());
        $team->delete();

        return back()->with('success', __('Das Team wurde gelöscht.'));
    }

    public function assignJobOrganization(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($job->company_id === $company->getKey(), 404);
        $validated = $this->validateOrganizationAssignment($request, $company);
        $before = $job->only(['company_team_id', 'contact_membership_id']);
        $job->update($validated);
        $audit->record('company.job_organization_assigned', $job, before: $before, after: $job->only(['company_team_id', 'contact_membership_id']), companyId: (int) $company->getKey());

        return back()->with('success', __('Die Organisationszuordnung der Stelle wurde gespeichert.'));
    }

    public function assignApplicationOrganization(
        Request $request,
        JobApplication $application,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($application->jobPosting()->where('company_id', $company->getKey())->exists(), 404);
        $validated = $this->validateOrganizationAssignment($request, $company);
        $before = $application->only(['company_team_id', 'contact_membership_id']);
        $application->update($validated);
        $audit->record('company.application_organization_assigned', $application, before: $before, after: $application->only(['company_team_id', 'contact_membership_id']), companyId: (int) $company->getKey());

        return back()->with('success', __('Die Organisationszuordnung der Bewerbung wurde gespeichert.'));
    }

    public function removeTeamMember(
        Request $request,
        CompanyMembership $membership,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($membership->company_id === $company->getKey(), 404);
        abort_if($membership->role === CompanyMemberRole::Owner, 422, __('Der Firmeninhaber kann nicht entfernt werden.'));
        $before = [
            'user_id' => $membership->user_id,
            'role' => $membership->role->value,
            'location_id' => $membership->location_id,
            'team_ids' => $membership->teams()->pluck('company_teams.id')->all(),
        ];
        $reassigned = DB::transaction(function () use ($company, $membership): array {
            $replacement = $company->memberships()
                ->where('role', CompanyMemberRole::Owner)
                ->whereNotNull('accepted_at')
                ->lockForUpdate()
                ->firstOrFail();
            $reminders = RecruiterReminder::query()
                ->where('company_id', $company->getKey())
                ->where('assignee_id', $membership->user_id)
                ->whereNull('completed_at')
                ->update(['assignee_id' => $replacement->user_id]);
            $cases = VisaCase::query()
                ->where('company_id', $company->getKey())
                ->where('assigned_to', $membership->user_id)
                ->whereNotIn('status', [VisaCaseStatus::Completed, VisaCaseStatus::Cancelled])
                ->update(['assigned_to' => $replacement->user_id]);
            $steps = VisaStep::query()
                ->where('responsible_user_id', $membership->user_id)
                ->whereHas('visaCase', fn ($query) => $query->where('company_id', $company->getKey()))
                ->whereNotIn('status', [VisaStepStatus::Completed, VisaStepStatus::NotRequired])
                ->update(['responsible_user_id' => $replacement->user_id]);
            $membership->delete();

            return compact('reminders', 'cases', 'steps');
        });
        $audit->record('company.member_removed', $company, before: $before, after: [
            'removed' => true,
            'reassigned' => $reassigned,
        ], companyId: (int) $company->getKey());

        return back()->with('success', __('Das Teammitglied wurde entfernt.'));
    }

    public function transferOwnership(
        Request $request,
        CompanyMembership $membership,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $actorMembership = $currentCompany->membership($request);
        abort_unless($actorMembership->role === CompanyMemberRole::Owner, 403);
        abort_unless(
            $membership->company_id === $company->getKey()
            && $membership->accepted_at !== null
            && $membership->getKey() !== $actorMembership->getKey(),
            404,
        );

        DB::transaction(function () use ($company, $actorMembership, $membership, $audit): void {
            $lockedOwner = $company->memberships()
                ->whereKey($actorMembership->getKey())->lockForUpdate()->firstOrFail();
            $lockedTarget = $company->memberships()
                ->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedOwner->role === CompanyMemberRole::Owner, 409, __('Die Eigentümerschaft wurde bereits geändert.'));
            $lockedOwner->update(['role' => CompanyMemberRole::Admin]);
            $lockedTarget->update(['role' => CompanyMemberRole::Owner]);
            $audit->record('company.ownership_transferred', $company, before: [
                'owner_user_id' => $lockedOwner->user_id,
            ], after: [
                'owner_user_id' => $lockedTarget->user_id,
            ]);
        }, 3);

        return back()->with('success', __('Die Eigentümerschaft wurde sicher übertragen.'));
    }

    public function visa(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = $currentCompany->forRequest($request);
        $cases = VisaCase::query()
            ->where('company_id', $company->getKey())
            ->with([
                'candidateProfile:id,user_id,first_name,last_name,current_position',
                'application.jobPosting:id,title',
                'steps.responsibleUser:id,name',
                'steps.tasks' => fn ($query) => $query
                    ->whereIn('visibility', ['company', 'shared'])
                    ->with('assignee:id,name'),
                'documents' => fn ($query) => $query
                    ->whereIn('visibility', ['company', 'shared'])
                    ->with('document:id,type,status,scan_result,expires_at,verified_at'),
                'events' => fn ($query) => $query
                    ->whereIn('visibility', ['company', 'shared'])
                    ->latest()
                    ->limit(30),
            ])
            ->latest()
            ->get();

        $responsibleUsers = User::query()
            ->where(function ($query) use ($company): void {
                $query->whereHas('companyMemberships', fn ($memberships) => $memberships
                    ->where('company_id', $company->getKey())
                    ->whereNotNull('accepted_at'))
                    ->orWhereIn('role', ['super_admin', 'support']);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('employer/Visa', [
            'cases' => $cases,
            'responsible_users' => $responsibleUsers,
        ]);
    }

    public function updateVisaStep(
        Request $request,
        VisaStep $step,
        CurrentCompany $currentCompany,
        CountryLaunchGate $countries,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $step->loadMissing('visaCase.application.jobPosting');
        $targetCountry = $step->visaCase->application?->jobPosting?->target_country_code;
        if (filled($targetCountry)) {
            $countries->assertEnabled(CountryOperation::Visa, $targetCountry);
        }
        abort_unless($step->visaCase()->where('company_id', $company->getKey())->exists(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(VisaStepStatus::class)],
            'due_at' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'blocker' => ['nullable', 'string', 'max:3000'],
            'completion_evidence' => [
                Rule::requiredIf(fn (): bool => $request->string('status')->toString() === VisaStepStatus::Completed->value),
                'nullable', 'string', 'min:3', 'max:5000',
            ],
        ]);
        abort_if(in_array($step->visaCase->status, [
            VisaCaseStatus::Completed,
            VisaCaseStatus::Cancelled,
        ], true), 422, __('Abgeschlossene Visa-Fälle können nicht verändert werden.'));
        if (isset($validated['responsible_user_id'])) {
            $responsibleIsValid = User::query()
                ->whereKey($validated['responsible_user_id'])
                ->where(function ($query) use ($company): void {
                    $query->whereHas('companyMemberships', fn ($memberships) => $memberships
                        ->where('company_id', $company->getKey())
                        ->whereNotNull('accepted_at'))
                        ->orWhereIn('role', ['super_admin', 'support']);
                })
                ->exists();
            abort_unless($responsibleIsValid, 422, __('Verantwortliche müssen zur Firma oder zum Plattformteam gehören.'));
        }
        $status = VisaStepStatus::from($validated['status']);
        $allowed = [
            VisaStepStatus::Open->value => [VisaStepStatus::InProgress, VisaStepStatus::Blocked, VisaStepStatus::Completed, VisaStepStatus::NotRequired],
            VisaStepStatus::InProgress->value => [VisaStepStatus::Open, VisaStepStatus::Blocked, VisaStepStatus::Completed, VisaStepStatus::NotRequired],
            VisaStepStatus::Blocked->value => [VisaStepStatus::InProgress, VisaStepStatus::NotRequired],
            VisaStepStatus::Completed->value => [VisaStepStatus::InProgress],
            VisaStepStatus::NotRequired->value => [VisaStepStatus::Open],
        ];
        abort_unless(
            $status === $step->status || in_array($status, $allowed[$step->status->value], true),
            422,
            __('Dieser Visa-Schrittübergang ist nicht erlaubt.'),
        );
        $before = $step->only([
            'status', 'due_at', 'responsible_user_id', 'notes', 'blocker',
            'completion_evidence', 'completed_at',
        ]);
        $next = [
            ...$validated,
            'completed_at' => $status === VisaStepStatus::Completed ? ($step->completed_at ?? now()) : null,
            'blocker' => $status === VisaStepStatus::Blocked ? ($validated['blocker'] ?? $step->blocker) : null,
        ];
        if ($this->sameVisaStepState($step, $next)) {
            return back()->with('success', __('Der Visa-Schritt war bereits aktuell.'));
        }
        $step->update([
            ...$next,
        ]);
        $case = $step->visaCase;
        $total = max(1, $case->steps()->count());
        $done = $case->steps()->whereIn('status', [
            VisaStepStatus::Completed,
            VisaStepStatus::NotRequired,
        ])->count();
        $progress = (int) round($done / $total * 100);
        $case->update([
            'progress' => $progress,
            'status' => $progress === 100
                ? VisaCaseStatus::Completed
                : ($case->status === VisaCaseStatus::Blocked
                    ? VisaCaseStatus::Blocked
                    : VisaCaseStatus::Active),
            'completed_at' => $progress === 100 ? now() : null,
            'version' => $case->version + 1,
        ]);
        app(AuditLogger::class)->record(
            'visa.step_updated',
            $step,
            before: $before,
            after: $step->only(array_keys($before)),
            request: $request,
            companyId: (int) $company->getKey(),
        );
        $case->events()->create([
            'actor_id' => $request->user()?->getKey(),
            'event' => 'visa.step.updated',
            'visibility' => $step->visibility,
            'payload' => [
                'visa_step_id' => $step->getKey(),
                'key' => $step->key,
                'status' => $status->value,
            ],
        ]);

        $case->loadMissing('candidateProfile.user');
        app(ProductNotificationDispatcher::class)->dispatch(
            $case->candidateProfile->user,
            'visa.step_updated',
            "visa-step:{$step->getKey()}:{$status->value}:".$step->updated_at?->getTimestamp(),
            [
                'title' => __('Visa-Schritt aktualisiert'),
                'message' => __('Ein Schritt in deinem Visa-Prozess wurde auf „:status“ gesetzt.', ['status' => $status->value]),
                'translations' => [
                    'de' => ['title' => 'Visa-Schritt aktualisiert', 'message' => "Ein Schritt in deinem Visa-Prozess wurde auf „{$status->value}“ gesetzt."],
                    'en' => ['title' => 'Visa step updated', 'message' => "A step in your visa process changed to “{$status->value}”."],
                ],
                'url' => route('dashboard'),
                'visa_case_id' => $case->getKey(),
                'visa_step_id' => $step->getKey(),
                'status' => $status->value,
            ],
        );

        return back()->with('success', __('Der Visa-Schritt wurde aktualisiert.'));
    }

    /** @param array<string, mixed> $next */
    private function sameVisaStepState(VisaStep $step, array $next): bool
    {
        foreach ($next as $key => $value) {
            $current = $step->getAttribute($key);
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ($current instanceof \DateTimeInterface) {
                $current = $current->format(str_contains($key, 'at') ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(str_contains($key, 'at') ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function assertManage(Request $request, CurrentCompany $currentCompany): void
    {
        abort_unless(
            in_array(
                Capability::TeamManage->value,
                app(CapabilityResolver::class)->forRequest($request),
                true,
            ),
            403,
        );
    }

    private function assertCanReserveSeat(
        Company $company,
        EntitlementService $entitlements,
        ?int $excludingInvitationId = null,
    ): void {
        $summary = $entitlements->summary($company);
        $seats = $summary['seats'];
        $limit = is_int($seats['limit'] ?? null) ? $seats['limit'] : null;
        if ($limit === null) {
            return;
        }
        $used = (int) ($seats['used'] ?? 0);
        $reserved = $company->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->when($excludingInvitationId !== null, fn ($query) => $query->whereKeyNot($excludingInvitationId))
            ->count();
        if ($used + $reserved >= $limit) {
            throw new DomainException(__('Das Sitzplatzkontingent ist einschließlich offener Einladungen ausgeschöpft.'));
        }
    }

    private function sendCompanyInvitation(Company $company, CompanyInvitation $invitation): void
    {
        $url = route('company-invitations.track', $invitation->token);
        try {
            Mail::raw(
                __('Du wurdest in das Faden-Team von :company eingeladen.', ['company' => $company->name])."\n\n".$url,
                fn ($message) => $message->to($invitation->email)->subject(__('Einladung zum Faden-Firmenportal')),
            );
        } catch (TransportExceptionInterface) {
            throw ValidationException::withMessages([
                'email' => __('Die Einladung wurde gespeichert, aber nicht versendet. Bitte nutze später „Erneut senden“.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validateTeam(Request $request, Company $company, ?CompanyTeam $team = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('company_teams', 'name')
                ->where('company_id', $company->getKey())->ignore($team)],
            'location_id' => ['nullable', 'integer', Rule::exists('company_locations', 'id')->where('company_id', $company->getKey())],
            'contact_membership_id' => ['nullable', 'integer', Rule::exists('company_memberships', 'id')
                ->where('company_id', $company->getKey())->whereNotNull('accepted_at')],
            'membership_ids' => ['array', 'max:100'],
            'membership_ids.*' => ['integer', 'distinct', Rule::exists('company_memberships', 'id')
                ->where('company_id', $company->getKey())->whereNotNull('accepted_at')],
        ]);

        return $validated;
    }

    /** @return array{company_team_id: int|null, contact_membership_id: int|null} */
    private function validateOrganizationAssignment(Request $request, Company $company): array
    {
        return $request->validate([
            'company_team_id' => ['nullable', 'integer', Rule::exists('company_teams', 'id')->where('company_id', $company->getKey())],
            'contact_membership_id' => ['nullable', 'integer', Rule::exists('company_memberships', 'id')
                ->where('company_id', $company->getKey())->whereNotNull('accepted_at')],
        ]);
    }

    /** @return array<string, mixed> */
    private function teamAuditValues(CompanyTeam $team): array
    {
        return [
            'name' => $team->name,
            'location_id' => $team->location_id,
            'contact_membership_id' => $team->contact_membership_id,
            'membership_ids' => $team->memberships()->orderBy('company_memberships.id')->pluck('company_memberships.id')->all(),
        ];
    }

    private function storeCompanyMedia(
        Company $company,
        Request $request,
        UploadedFile $file,
        string $type,
    ): CompanyMedia {
        $path = $file->store("companies/{$company->getKey()}/profile", 'private');
        abort_if($path === false, 500, __('Das Firmenmedium konnte nicht privat gespeichert werden.'));
        $media = $company->media()->create([
            'uploaded_by' => $request->user()?->getKey(),
            'type' => $type,
            'disk' => 'private',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'scan_result' => 'pending',
        ]);
        ScanCompanyMedia::dispatch($media->getKey())->afterCommit();

        return $media;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(CompanyMedia $media): array
    {
        return [
            'id' => $media->getKey(),
            'type' => $media->type,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'scan_result' => $media->scan_result,
            'is_logo' => $media->company->logo_media_id === $media->getKey(),
            'download_url' => $media->scan_result === 'clean'
                ? URL::temporarySignedRoute(
                    'companies.media.download',
                    now()->addMinutes(15),
                    ['media' => $media],
                )
                : null,
        ];
    }
}
