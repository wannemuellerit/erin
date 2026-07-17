<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

abstract class AdminController extends Controller
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(
        Request $request,
        string $event,
        ?Model $auditable = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $request->user()?->getKey(),
            'company_id' => $auditable?->getAttribute('company_id'),
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

    protected function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    protected function auditDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)->toIso8601String()
            : null;
    }

    protected function dateIsFuture(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->isFuture();
        }

        return is_string($value) && $value !== '' && Carbon::parse($value)->isFuture();
    }
}
