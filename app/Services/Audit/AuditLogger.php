<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?Request $request = null,
        ?int $companyId = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'actor_id' => $request->user()?->getKey(),
            'company_id' => $companyId ?? $request->attributes->get('company_id'),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'before_values' => $before ?: null,
            'after_values' => $after ?: null,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
