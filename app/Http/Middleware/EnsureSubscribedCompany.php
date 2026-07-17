<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribedCompany
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->attributes->get('company_id');
        abort_if($companyId === null, Response::HTTP_FORBIDDEN);

        $company = DB::table('companies')->where('id', $companyId)->first();
        abort_if($company === null, Response::HTTP_NOT_FOUND);
        abort_if(
            in_array($company->status, [
                CompanyStatus::Suspended->value,
                CompanyStatus::Blocked->value,
            ], true),
            Response::HTTP_FORBIDDEN,
            __('Der Zugang dieses Unternehmens ist derzeit gesperrt.'),
        );

        $hasPortalAccess = in_array($company->subscription_status, [
            'active',
            'trialing',
            'past_due',
        ], true);

        if (! $hasPortalAccess) {
            return redirect()->route('employer.billing')->with(
                'warning',
                __('Bitte schließe zuerst die Zahlung für dein Firmenpaket ab.'),
            );
        }

        return $next($request);
    }
}
