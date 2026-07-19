<?php

namespace App\Http\Controllers\Employer;

use App\Enums\Capability;
use App\Enums\CompanyMemberRole;
use App\Enums\VisaCaseStatus;
use App\Enums\VisaStepStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ScanCompanyMedia;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMedia;
use App\Models\CompanyMembership;
use App\Models\VisaCase;
use App\Models\VisaStep;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\CapabilityResolver;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use App\Services\Documents\UploadPolicy;
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
use Inertia\Inertia;
use Inertia\Response;

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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'industry' => ['required', 'string', 'max:120'],
            'employee_count' => ['nullable', 'integer', 'min:1'],
            'country_code' => ['required', 'string', 'size:2'],
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
            $company->locations()->delete();
            $company->locations()->createMany($validated['locations'] ?? []);

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
            'members' => $company->memberships()->with('user:id,name,email,last_active_at')->get(),
            'invitations' => $company->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->get(),
            'teams' => $company->teams()->with('memberships.user:id,name,email')->get(),
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
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(CompanyMemberRole::class)->only([
                CompanyMemberRole::Admin,
                CompanyMemberRole::Recruiter,
                CompanyMemberRole::Viewer,
            ])],
        ]);

        try {
            $entitlements->assertCanAddSeat($company);
        } catch (DomainException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        /** @var CompanyInvitation $invitation */
        $invitation = $company->invitations()->updateOrCreate(
            ['email' => mb_strtolower($validated['email'])],
            [
                'invited_by' => $request->user()?->getKey(),
                'role' => $validated['role'],
                'token' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );
        $url = route('company-invitations.track', $invitation->token);

        Mail::raw(
            __('Du wurdest in das Erin-Team von :company eingeladen.', ['company' => $company->name])."\n\n".$url,
            fn ($message) => $message->to($invitation->email)->subject(__('Einladung zum Erin-Firmenportal')),
        );

        return back()->with('success', __('Die Teameinladung wurde versendet.'));
    }

    public function trackInvitation(Request $request, string $token): RedirectResponse
    {
        /** @var CompanyInvitation $invitation */
        $invitation = CompanyInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
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
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        /** @var CompanyInvitation $invitation */
        $invitation = CompanyInvitation::query()
            ->with('company')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
        if (! hash_equals(mb_strtolower($invitation->email), mb_strtolower($user->email))) {
            abort(403);
        }

        try {
            DB::transaction(function () use ($invitation, $user, $entitlements): void {
                $company = $invitation->company()->with('plan')->lockForUpdate()->firstOrFail();
                $lockedInvitation = CompanyInvitation::query()
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless(
                    $lockedInvitation->accepted_at === null && $lockedInvitation->expires_at->isFuture(),
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
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['invitation' => $exception->getMessage()]);
        }

        $request->session()->put('active_company_id', $invitation->company_id);

        return redirect()->route('dashboard')->with('success', __('Du bist dem Firmenteam beigetreten.'));
    }

    public function removeTeamMember(
        Request $request,
        CompanyMembership $membership,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertManage($request, $currentCompany);
        abort_unless($membership->company_id === $company->getKey(), 404);
        abort_if($membership->role === CompanyMemberRole::Owner, 422, __('Der Firmeninhaber kann nicht entfernt werden.'));
        $membership->delete();

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
            ])
            ->latest()
            ->get();

        return Inertia::render('employer/Visa', ['cases' => $cases]);
    }

    public function updateVisaStep(
        Request $request,
        VisaStep $step,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($step->visaCase()->where('company_id', $company->getKey())->exists(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(VisaStepStatus::class)],
            'due_at' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'integer'],
        ]);
        $status = VisaStepStatus::from($validated['status']);
        $step->update([
            ...$validated,
            'completed_at' => $status === VisaStepStatus::Completed ? now() : null,
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
            'status' => $progress === 100 ? VisaCaseStatus::Completed : VisaCaseStatus::Active,
            'completed_at' => $progress === 100 ? now() : null,
        ]);

        return back()->with('success', __('Der Visa-Schritt wurde aktualisiert.'));
    }

    private function assertManage(Request $request, CurrentCompany $currentCompany): void
    {
        abort_unless(
            in_array(
                Capability::CompanyManage->value,
                app(CapabilityResolver::class)->forRequest($request),
                true,
            ),
            403,
        );
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
