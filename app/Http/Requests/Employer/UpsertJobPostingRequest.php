<?php

namespace App\Http\Requests\Employer;

use App\Services\Documents\UploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'position' => ['required', 'string', 'min:2', 'max:180'],
            'summary' => ['required', 'string', 'min:40', 'max:1000'],
            'description' => ['required', 'string', 'min:100', 'max:50000'],
            'responsibilities' => ['required', 'string', 'min:40', 'max:20000'],
            'requirements' => ['required', 'string', 'min:40', 'max:20000'],
            'benefits' => ['nullable', 'string', 'max:10000'],
            'occupation_id' => ['required', 'exists:occupations,id'],
            'location_id' => ['nullable', 'exists:company_locations,id'],
            'expected_experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
            'language_notes' => ['nullable', 'string', 'max:3000'],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'vacancies' => ['required', 'integer', 'min:1', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:180'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'hours_min' => ['required', 'integer', 'min:1', 'max:80'],
            'hours_max' => ['required', 'integer', 'gte:hours_min', 'max:80'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'temporary', 'permanent'])],
            'compensation_min_cents' => ['required', 'integer', 'min:1'],
            'compensation_max_cents' => ['nullable', 'integer', 'gte:compensation_min_cents'],
            'currency' => ['required', Rule::in(['EUR'])],
            'compensation_interval' => ['required', Rule::in(['hour', 'month', 'year'])],
            'salary_visible' => ['boolean'],
            'is_remote' => ['boolean'],
            'visa_package_available' => ['boolean'],
            'skills' => ['required', 'array', 'min:1', 'max:30'],
            'skills.*.id' => ['required', 'distinct', 'exists:skills,id'],
            'skills.*.importance' => ['required', 'integer', 'min:1', 'max:5'],
            'skills.*.minimum_experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'languages' => ['required', 'array', 'min:1', 'max:20'],
            'languages.*.id' => ['required', 'distinct', 'exists:languages,id'],
            'languages.*.minimum_level' => ['required', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])],
            'languages.*.is_required' => ['boolean'],
            'screening_questions' => ['array', 'max:5'],
            'screening_questions.*.question' => ['required', 'string', 'max:500'],
            'screening_questions.*.type' => ['required', Rule::in(['text', 'yes_no', 'choice'])],
            'screening_questions.*.is_required' => ['boolean'],
            'screening_questions.*.options' => ['nullable', 'array', 'max:10'],
            'screening_questions.*.options.*' => ['required', 'string', 'max:180', 'distinct'],
            'media' => ['array', 'max:10'],
            'media.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,pdf,doc,docx',
                'max:'.app(UploadPolicy::class)->maxFileKilobytes(10240),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('is_remote') && blank($this->input('location_id'))) {
                $validator->errors()->add('location_id', __('Für eine Stelle vor Ort muss ein Standort ausgewählt werden.'));
            }
            foreach ($this->input('screening_questions', []) as $index => $question) {
                if (($question['type'] ?? null) !== 'choice') {
                    continue;
                }
                $options = array_values(array_unique(array_filter($question['options'] ?? [], 'is_string')));
                if (count($options) < 2) {
                    $validator->errors()->add("screening_questions.{$index}.options", __('Auswahlfragen benötigen mindestens zwei unterschiedliche Antworten.'));
                }
            }
        }];
    }
}
