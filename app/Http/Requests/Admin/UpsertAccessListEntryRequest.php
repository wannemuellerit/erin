<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\AccessListEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertAccessListEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SuperAdmin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entry = $this->route('accessListEntry');

        return [
            'list_type' => ['required', Rule::in(['blacklist', 'whitelist'])],
            'subject_type' => ['required', Rule::in(['email', 'domain', 'ip'])],
            'value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('access_list_entries', 'value')
                    ->where(fn ($query) => $query
                        ->where('list_type', $this->input('list_type'))
                        ->where('subject_type', $this->input('subject_type')))
                    ->ignore($entry instanceof AccessListEntry ? $entry->getKey() : null),
            ],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $subjectType = $this->string('subject_type')->toString();
            $value = $this->string('value')->toString();

            $valid = match ($subjectType) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'domain' => filter_var(
                    $value,
                    FILTER_VALIDATE_DOMAIN,
                    FILTER_FLAG_HOSTNAME,
                ) !== false,
                'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
                default => false,
            };

            if (! $valid) {
                $validator->errors()->add(
                    'value',
                    __('Der Wert passt nicht zum gewählten Eintragstyp.'),
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $listType = mb_strtolower(trim((string) $this->input('list_type')));
        $subjectType = mb_strtolower(trim((string) $this->input('subject_type')));
        $value = trim((string) $this->input('value'));

        if (in_array($subjectType, ['email', 'domain'], true)) {
            $value = mb_strtolower($value);
        }

        if ($subjectType === 'domain') {
            $value = rtrim($value, '.');
        }

        $this->merge([
            'list_type' => $listType,
            'subject_type' => $subjectType,
            'value' => $value,
        ]);
    }
}
