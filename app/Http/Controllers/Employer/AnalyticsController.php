<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Services\Analytics\RecruitingAnalyticsService;
use App\Services\Audit\AuditLogger;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentCompany $currentCompany,
        RecruitingAnalyticsService $analytics,
    ): Response {
        $company = $currentCompany->forRequest($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date', 'before_or_equal:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(89);

        if ($from->diffInDays($to) > 730) {
            $from = $to->copy()->subDays(730);
        }

        return Inertia::render('employer/Analytics', [
            'analytics' => $analytics->forCompany($company, $from, $to),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        RecruitingAnalyticsService $analytics,
        AuditLogger $audit,
    ): StreamedResponse {
        $company = $currentCompany->forRequest($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date', 'before_or_equal:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(89);
        abort_if($from->diffInDays($to) > 730, 422, __('Der Exportzeitraum ist zu groß.'));
        $report = $analytics->forCompany($company, $from, $to);
        $audit->record('analytics.company_exported', $company, metadata: [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'format' => 'csv',
        ], request: $request, companyId: $company->getKey());

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'wb');
            abort_if($stream === false, 500);
            fputcsv($stream, ['job', 'status', 'applications', 'interviews', 'hires', 'interview_rate', 'hire_rate']);
            foreach ($report['jobs'] as $job) {
                $title = preg_match('/^[=+\-@]/', (string) $job['title']) === 1
                    ? "'".$job['title']
                    : $job['title'];
                fputcsv($stream, [
                    $title, $job['status'], $job['applications'], $job['interviews'],
                    $job['hires'], $job['interview_rate'], $job['hire_rate'],
                ]);
            }
            fclose($stream);
        }, "erin-analytics-{$from->toDateString()}-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
