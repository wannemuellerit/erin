<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyMember
{
    /**
     * Resolve the selected company from the URL, session, or first accepted membership.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED);

        $routeCompany = $request->route('company');
        $companyId = $routeCompany instanceof Model
            ? $routeCompany->getKey()
            : (is_numeric($routeCompany) ? (int) $routeCompany : null);

        $companyId ??= $request->session()->get('active_company_id');

        $membership = DB::table('company_memberships')
            ->where('user_id', $user->getKey())
            ->whereNotNull('accepted_at')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->first();

        abort_if($membership === null, Response::HTTP_FORBIDDEN, __('Du gehörst keinem aktiven Unternehmen an.'));

        $request->session()->put('active_company_id', $membership->company_id);
        $request->attributes->set('company_id', (int) $membership->company_id);
        $request->attributes->set('company_membership', $membership);

        return $next($request);
    }
}
