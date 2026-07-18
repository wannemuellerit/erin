<?php

namespace App\Http\Controllers;

use App\Contracts\VideoProvider;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\InterviewProposal;
use App\Models\JobApplication;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Applications\ApplicationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InterviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $query = Interview::query()->with([
            'application.jobPosting.company:id,name',
            'application.candidateProfile.user:id,name',
            'proposals.proposer:id,name',
            'organizer:id,name',
        ]);

        if ($user->role === UserRole::Company) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->whereHas('application.jobPosting', fn ($jobs) => $jobs->whereIn('company_id', $companyIds));
        } else {
            $query->whereHas('application.candidateProfile', fn ($profiles) => $profiles->where('user_id', $user->getKey()));
        }

        $interviews = $query->orderByRaw('starts_at is null')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Interview $interview): array => [
                ...$interview->toArray(),
                'ics_url' => $interview->starts_at ? URL::temporarySignedRoute(
                    'interviews.ics',
                    now()->addMinutes(30),
                    ['interview' => $interview],
                ) : null,
            ]);

        return Inertia::render(
            $user->role === UserRole::Company ? 'employer/Interviews' : 'candidate/Interviews',
            [
                'interviews' => $interviews,
                'availability' => $user->availabilitySlots()->orderBy('weekday')->orderBy('starts_at')->get(),
                'timezone' => $user->timezone,
            ],
        );
    }

    public function propose(
        Request $request,
        JobApplication $application,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $this->authorizeApplication($request, $application, true);
        $validated = $request->validate([
            'slots' => ['required', 'array', 'min:1', 'max:5'],
            'slots.*.starts_at' => ['required', 'date', 'after:now'],
            'slots.*.ends_at' => ['required', 'date', 'after:slots.*.starts_at'],
            'slots.*.timezone' => ['required', 'timezone'],
            'slots.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        foreach ($validated['slots'] as $slot) {
            abort_unless(strtotime($slot['ends_at']) > strtotime($slot['starts_at']), 422);
        }

        $interview = DB::transaction(function () use ($application, $request, $validated): Interview {
            $interview = Interview::query()->create([
                'application_id' => $application->getKey(),
                'organizer_id' => $request->user()?->getKey(),
                'proposed_by' => $request->user()?->getKey(),
                'status' => InterviewStatus::Proposed,
                'timezone' => $validated['slots'][0]['timezone'],
            ]);
            $interview->update([
                'livekit_room_name' => 'erin-interview-'.$interview->getKey().'-'.Str::lower(Str::random(10)),
            ]);

            foreach ($validated['slots'] as $slot) {
                $interview->proposals()->create([
                    'proposed_by' => $request->user()?->getKey(),
                    'starts_at' => $slot['starts_at'],
                    'ends_at' => $slot['ends_at'],
                    'timezone' => $slot['timezone'],
                    'status' => 'pending',
                    'note' => $slot['note'] ?? null,
                ]);
            }

            return $interview;
        });

        $this->notifyOtherParticipant($request, $application, [
            'event' => 'interview.proposed',
            'title' => __('Neue Terminvorschläge'),
            'message' => __('Für „:job“ wurden Interviewtermine vorgeschlagen.', [
                'job' => $application->jobPosting->title,
            ]),
            'translations' => [
                'de' => [
                    'title' => 'Neue Terminvorschläge',
                    'message' => sprintf(
                        'Für „%s“ wurden Interviewtermine vorgeschlagen.',
                        $application->jobPosting->title,
                    ),
                ],
                'en' => [
                    'title' => 'New interview suggestions',
                    'message' => sprintf(
                        'Interview times were suggested for “%s”.',
                        $application->jobPosting->title,
                    ),
                ],
            ],
            'url' => route('interviews.index'),
            'interview_id' => $interview->getKey(),
        ]);
        $activity->record(
            'interview.proposed',
            $request->user(),
            $application->jobPosting->company_id,
            $interview,
            [
                'candidate_label' => $application->candidateProfile->anonymizedLabel(),
                'job_title' => $application->jobPosting->title,
            ],
            $application->candidateProfile->user,
            'shared',
        );

        return redirect()->route('interviews.index')->with('success', __('Die Terminvorschläge wurden gesendet.'));
    }

    public function respond(
        Request $request,
        Interview $interview,
        ApplicationWorkflow $workflow,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $interview->load('application.jobPosting');
        $this->authorizeApplication($request, $interview->application, true);
        $validated = $request->validate([
            'response' => ['required', Rule::in(['accept', 'counter', 'cancel'])],
            'proposal_id' => ['nullable', 'required_if:response,accept', 'integer'],
            'slots' => ['nullable', 'required_if:response,counter', 'array', 'min:1', 'max:5'],
            'slots.*.starts_at' => ['required_with:slots', 'date', 'after:now'],
            'slots.*.ends_at' => ['required_with:slots', 'date'],
            'slots.*.timezone' => ['required_with:slots', 'timezone'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['response'] === 'cancel') {
            $interview->update([
                'status' => InterviewStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['note'] ?? null,
            ]);
            $activity->record(
                'interview.cancelled',
                $request->user(),
                $interview->application->jobPosting->company_id,
                $interview,
                ['job_title' => $interview->application->jobPosting->title],
            );

            return back()->with('success', __('Das Interview wurde abgesagt.'));
        }

        if ($validated['response'] === 'counter') {
            DB::transaction(function () use ($interview, $request, $validated): void {
                $interview->proposals()->where('status', 'pending')->update([
                    'status' => 'superseded',
                    'responded_at' => now(),
                ]);
                foreach ($validated['slots'] as $slot) {
                    abort_unless(strtotime($slot['ends_at']) > strtotime($slot['starts_at']), 422);
                    $interview->proposals()->create([
                        'proposed_by' => $request->user()?->getKey(),
                        'starts_at' => $slot['starts_at'],
                        'ends_at' => $slot['ends_at'],
                        'timezone' => $slot['timezone'],
                        'status' => 'pending',
                        'note' => $validated['note'] ?? null,
                    ]);
                }
                $interview->update(['status' => InterviewStatus::CounterProposed]);
            });
            $activity->record(
                'interview.counter_proposed',
                $request->user(),
                $interview->application->jobPosting->company_id,
                $interview,
                ['job_title' => $interview->application->jobPosting->title],
            );

            return back()->with('success', __('Deine Gegenvorschläge wurden gesendet.'));
        }

        /** @var InterviewProposal $proposal */
        $proposal = $interview->proposals()
            ->where('status', 'pending')
            ->findOrFail($validated['proposal_id']);
        abort_if($proposal->proposed_by === $request->user()?->getKey(), 422, __('Eigene Vorschläge können nicht selbst bestätigt werden.'));

        DB::transaction(function () use ($interview, $proposal, $request, $workflow): void {
            $interview->proposals()->whereKeyNot($proposal->getKey())->where('status', 'pending')->update([
                'status' => 'rejected',
                'responded_at' => now(),
            ]);
            $proposal->update(['status' => 'accepted', 'responded_at' => now()]);
            $interview->update([
                'status' => InterviewStatus::Confirmed,
                'starts_at' => $proposal->starts_at,
                'ends_at' => $proposal->ends_at,
                'timezone' => $proposal->timezone,
                'confirmed_at' => now(),
            ]);

            $application = $interview->application;
            if ($workflow->canTransition($application->status, ApplicationStatus::InterviewScheduled)) {
                $from = $application->status;
                $application->update(['status' => ApplicationStatus::InterviewScheduled]);
                $application->statusHistory()->create([
                    'changed_by' => $request->user()?->getKey(),
                    'from_status' => $from->value,
                    'to_status' => ApplicationStatus::InterviewScheduled->value,
                    'note' => __('Interview bestätigt'),
                ]);
            }
        });

        $this->notifyOtherParticipant($request, $interview->application, [
            'event' => 'interview.confirmed',
            'title' => __('Interview bestätigt'),
            'message' => __('Der Interviewtermin wurde bestätigt.'),
            'translations' => [
                'de' => [
                    'title' => 'Interview bestätigt',
                    'message' => 'Der Interviewtermin wurde bestätigt.',
                ],
                'en' => [
                    'title' => 'Interview confirmed',
                    'message' => 'The interview appointment has been confirmed.',
                ],
            ],
            'url' => route('interviews.index'),
            'interview_id' => $interview->getKey(),
        ]);
        $activity->record(
            'interview.confirmed',
            $request->user(),
            $interview->application->jobPosting->company_id,
            $interview,
            ['job_title' => $interview->application->jobPosting->title],
        );

        return back()->with('success', __('Der Interviewtermin wurde bestätigt.'));
    }

    public function token(
        Request $request,
        Interview $interview,
        VideoProvider $video,
    ): JsonResponse {
        $interview->load('application.jobPosting');
        $this->authorizeApplication($request, $interview->application);
        abort_unless($interview->status === InterviewStatus::Confirmed, 422, __('Das Interview ist nicht bestätigt.'));
        abort_if($interview->starts_at === null || $interview->ends_at === null, 422);
        abort_unless(
            now()->between($interview->starts_at->copy()->subMinutes(15), $interview->ends_at->copy()->addMinutes(15)),
            403,
            __('Der Videoraum ist erst 15 Minuten vor dem Termin verfügbar.'),
        );
        $metadata = $interview->metadata ?? [];

        if (! isset($metadata['e2ee_key'])) {
            $metadata['e2ee_key'] = Crypt::encryptString(base64_encode(random_bytes(32)));
            $interview->update(['metadata' => $metadata]);
        }

        $e2eeKey = Crypt::decryptString($metadata['e2ee_key']);
        $user = $request->user();
        abort_if($user === null, 401);
        $access = $video->issueAccess(
            (string) $interview->livekit_room_name,
            'erin-user-'.$user->getKey(),
            $user->name,
            [
                'e2ee_key' => $e2eeKey,
                'interview_id' => $interview->getKey(),
                'recording_allowed' => false,
                'region' => config('services.livekit.region'),
            ],
        );

        return response()->json($access);
    }

    public function ics(Request $request, Interview $interview): HttpResponse
    {
        $interview->load('application.jobPosting.company');
        $this->authorizeApplication($request, $interview->application);
        abort_if($interview->starts_at === null || $interview->ends_at === null, 404);
        $title = 'Erin Interview – '.$interview->application->jobPosting->title;
        $description = 'Videointerview über Erin mit '.$interview->application->jobPosting->company->name;
        $content = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Erin//Recruiting OS//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:interview-'.$interview->getKey().'@erin',
            'DTSTAMP:'.now()->utc()->format('Ymd\\THis\\Z'),
            'DTSTART:'.$interview->starts_at->utc()->format('Ymd\\THis\\Z'),
            'DTEND:'.$interview->ends_at->utc()->format('Ymd\\THis\\Z'),
            'SUMMARY:'.$this->icsEscape($title),
            'DESCRIPTION:'.$this->icsEscape($description),
            'URL:'.$this->icsEscape(route('interviews.index')),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return response($content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="erin-interview-'.$interview->getKey().'.ics"',
        ]);
    }

    public function updateAvailability(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $validated = $request->validate([
            'slots' => ['array', 'max:28'],
            'slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'],
            'slots.*.ends_at' => ['required', 'date_format:H:i'],
            'slots.*.timezone' => ['required', 'timezone'],
        ]);
        /** @var list<array{weekday: int, starts_at: string, ends_at: string, timezone: string}> $availabilitySlots */
        $availabilitySlots = is_array($validated['slots'] ?? null) ? array_values($validated['slots']) : [];
        $grouped = collect($availabilitySlots)->groupBy('weekday');

        foreach ($grouped as $slots) {
            $sorted = $slots->sortBy('starts_at')->values();
            foreach ($sorted as $index => $slot) {
                abort_unless($slot['starts_at'] < $slot['ends_at'], 422, __('Das Ende muss nach dem Beginn liegen.'));
                if ($index > 0) {
                    abort_unless($sorted[$index - 1]['ends_at'] <= $slot['starts_at'], 422, __('Verfügbarkeiten dürfen sich nicht überschneiden.'));
                }
            }
        }

        DB::transaction(function () use ($user, $availabilitySlots): void {
            $user->availabilitySlots()->delete();
            $user->availabilitySlots()->createMany($availabilitySlots);
        });

        return back()->with('success', __('Deine Verfügbarkeit wurde gespeichert.'));
    }

    private function authorizeApplication(
        Request $request,
        JobApplication $application,
        bool $write = false,
    ): void {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->role === UserRole::Candidate) {
            abort_unless($application->candidateProfile()->where('user_id', $user->getKey())->exists(), 403);

            return;
        }

        $companyId = $application->jobPosting->company_id;
        abort_unless($user->belongsToCompany($companyId), 403);

        if ($write) {
            abort_unless($user->hasCompanyRole($companyId, [
                CompanyMemberRole::Owner,
                CompanyMemberRole::Admin,
                CompanyMemberRole::Recruiter,
            ]), 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyOtherParticipant(Request $request, JobApplication $application, array $data): void
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $other = $user->role === UserRole::Candidate
            ? $application->jobPosting->creator
            : $application->candidateProfile->user;
        $other->notify(new ActivityNotification($data));
    }

    private function icsEscape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }
}
