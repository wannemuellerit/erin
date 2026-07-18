<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateThemeRequest extends FormRequest
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
        $keys = implode(',', array_keys(PlatformSettings::DEFAULT_COLORS));

        $rules = [
            'colors' => ['required', "array:{$keys}"],
        ];

        foreach (array_keys(PlatformSettings::DEFAULT_COLORS) as $key) {
            $rules["colors.{$key}"] = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string, mixed> $colors */
            $colors = $this->input('colors', []);

            if (count($colors) !== count(PlatformSettings::DEFAULT_COLORS)) {
                return;
            }

            foreach ($colors as $color) {
                if (! is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                    return;
                }
            }

            $pairs = [
                ['text', 'background', 4.5, __('Text und Hintergrund benötigen mindestens Kontrast 4,5:1.')],
                ['text', 'surface', 4.5, __('Text und Karten benötigen mindestens Kontrast 4,5:1.')],
                ['text_muted', 'background', 4.5, __('Sekundärtext und Hintergrund benötigen mindestens Kontrast 4,5:1.')],
                ['primary', 'background', 3.0, __('Primärfarbe und Hintergrund benötigen mindestens Kontrast 3:1.')],
                ['primary', '#FFFFFF', 4.5, __('Primärfarbe und weiße Buttonschrift benötigen mindestens Kontrast 4,5:1.')],
                ['primary_hover', '#FFFFFF', 4.5, __('Hoverfarbe und weiße Buttonschrift benötigen mindestens Kontrast 4,5:1.')],
            ];

            foreach ($pairs as [$foreground, $background, $minimum, $message]) {
                if ($this->contrastRatio(
                    (string) $colors[$foreground],
                    str_starts_with($background, '#')
                        ? $background
                        : (string) $colors[$background],
                ) < $minimum) {
                    $validator->errors()->add("colors.{$foreground}", $message);
                }
            }
        });
    }

    private function contrastRatio(string $first, string $second): float
    {
        $lighter = max($this->luminance($first), $this->luminance($second));
        $darker = min($this->luminance($first), $this->luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];

        $channels = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }
}
