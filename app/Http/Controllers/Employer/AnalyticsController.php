<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Services\Analytics\RecruitingAnalyticsService;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

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
}
