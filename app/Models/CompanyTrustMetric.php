<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string|null $response_rate
 * @property string|null $interview_attendance_rate
 * @property string|null $contract_compliance_rate
 * @property int $cases_count
 * @property bool $is_top_company
 * @property Carbon|null $calculated_at
 */
class CompanyTrustMetric extends Model
{
    public const MIN_PUBLIC_CASES = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'response_rate' => 'decimal:2',
            'interview_attendance_rate' => 'decimal:2',
            'contract_compliance_rate' => 'decimal:2',
            'cases_count' => 'integer',
            'is_top_company' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isPublic(): bool
    {
        return $this->cases_count >= self::MIN_PUBLIC_CASES
            && $this->calculated_at !== null;
    }

    /**
     * @return array{
     *     response_rate: float|null,
     *     interview_attendance_rate: float|null,
     *     contract_compliance_rate: float|null,
     *     cases_count: int,
     *     is_top_company: bool,
     *     calculated_at: string
     * }|null
     */
    public function publicPayload(): ?array
    {
        if (! $this->isPublic()) {
            return null;
        }

        return [
            'response_rate' => $this->response_rate === null ? null : (float) $this->response_rate,
            'interview_attendance_rate' => $this->interview_attendance_rate === null
                ? null
                : (float) $this->interview_attendance_rate,
            'contract_compliance_rate' => $this->contract_compliance_rate === null
                ? null
                : (float) $this->contract_compliance_rate,
            'cases_count' => $this->cases_count,
            'is_top_company' => $this->is_top_company,
            'calculated_at' => $this->calculated_at->toIso8601String(),
        ];
    }
}
