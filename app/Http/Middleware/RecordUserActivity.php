<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecordUserActivity
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record(
                $request,
                $exception instanceof HttpExceptionInterface
                    ? $exception->getStatusCode()
                    : Response::HTTP_INTERNAL_SERVER_ERROR,
            );

            throw $exception;
        }

        $this->record($request, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, int $responseStatus): void
    {
        $user = $request->user();
        $route = $request->route();
        $routeName = $route?->getName();

        if ($user === null || $routeName === null || str_starts_with($routeName, 'health.')) {
            return;
        }

        try {
            $this->audit->record(
                $request->isMethodSafe() ? 'user.page_viewed' : 'user.action_performed',
                metadata: [
                    'route' => $routeName,
                    'method' => $request->method(),
                    'response_status' => $responseStatus,
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ],
                request: $request,
            );
        } catch (Throwable $exception) {
            Log::warning('audit.user_activity_failed', [
                'route' => $routeName,
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
