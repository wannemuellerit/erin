<?php

namespace App\Services\Candidates;

use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Arr;

final class ProfileCompletenessCalculator
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @var array<string, int>
     */
    public const WEIGHTS = [
        'personal' => 10,
        'photo' => 5,
        'profession' => 15,
        'experience' => 15,
        'skills' => 10,
        'languages' => 10,
        'education' => 10,
        'cv' => 10,
        'certificates' => 10,
        'availability' => 5,
    ];

    /**
     * @param  array<string, mixed>  $profile
     * @return array{percentage: int, completed: list<string>, missing: list<string>, can_apply: bool, required_percentage: int}
     */
    public function calculate(array $profile): array
    {
        $checks = [
            'personal' => $this->allFilled($profile, [
                'first_name',
                'last_name',
                'current_country_code',
                'current_city',
                'phone',
            ]),
            'photo' => filled(Arr::get($profile, 'profile_photo_path')),
            'profession' => filled(Arr::get($profile, 'occupation_id'))
                && filled(Arr::get($profile, 'desired_position'))
                && filled(Arr::get($profile, 'summary')),
            'experience' => (int) Arr::get($profile, 'work_experiences_count', 0) > 0,
            'skills' => (int) Arr::get($profile, 'skills_count', 0) > 0,
            'languages' => (int) Arr::get($profile, 'languages_count', 0) > 0,
            'education' => (int) Arr::get($profile, 'educations_count', 0) > 0,
            'cv' => (bool) Arr::get($profile, 'has_cv', false),
            'certificates' => (bool) Arr::get($profile, 'has_verified_certificate', false),
            'availability' => filled(Arr::get($profile, 'available_from'))
                && Arr::has($profile, 'relocation_ready'),
        ];

        $completed = [];
        $missing = [];
        $percentage = 0;

        foreach ($checks as $section => $isComplete) {
            if ($isComplete) {
                $percentage += self::WEIGHTS[$section];
                $completed[] = $section;
            } else {
                $missing[] = $section;
            }
        }

        return [
            'percentage' => $percentage,
            'completed' => $completed,
            'missing' => $missing,
            'can_apply' => $percentage >= $this->threshold(),
            'required_percentage' => $this->threshold(),
        ];
    }

    public function threshold(): int
    {
        // Pure unit tests intentionally run without a bootstrapped Laravel
        // application. Keep the product default available in that context.
        if (! app()->bound('config')) {
            return 80;
        }

        return min(100, max(50, (int) $this->settings->get(
            'candidate_profile.minimum_completion',
            80,
        )));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function allFilled(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! filled(Arr::get($data, $key))) {
                return false;
            }
        }

        return true;
    }
}
