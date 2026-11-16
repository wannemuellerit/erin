<?php

namespace App\Services\Jobs;

use App\Models\JobPosting;

class JobPublishingReadiness
{
    /** @return list<string> */
    public function missing(JobPosting $job): array
    {
        $job->loadMissing(['skills', 'languages']);
        $missing = [];
        foreach ([
            'title' => __('Titel'),
            'position' => __('Position'),
            'summary' => __('Kurzbeschreibung'),
            'description' => __('Beschreibung'),
            'responsibilities' => __('Aufgaben'),
            'requirements' => __('Anforderungen'),
            'occupation_id' => __('Berufsfeld'),
            'hours_min' => __('Mindeststunden'),
            'hours_max' => __('Maximalstunden'),
            'compensation_min_cents' => __('Vergütung'),
        ] as $field => $label) {
            if (blank($job->getAttribute($field))) {
                $missing[] = $label;
            }
        }
        if (! $job->is_remote && $job->location_id === null) {
            $missing[] = __('Standort');
        }
        if ($job->skills->isEmpty()) {
            $missing[] = __('mindestens ein Skill');
        }
        if ($job->languages->isEmpty()) {
            $missing[] = __('mindestens eine Sprache');
        }

        return $missing;
    }
}
