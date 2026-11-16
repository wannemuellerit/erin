<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

function backendTranslationLiteralKeys(): array
{
    $keys = [];
    $pattern = <<<'REGEX'
        /__\(\s*(?:'((?:\\\\.|[^'\\\\])*)'|"((?:\\\\.|[^"\\\\])*)")/s
        REGEX;

    foreach ([app_path(), base_path('routes'), database_path()] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                $pattern,
                File::get($file->getPathname()),
                $matches,
                PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
            );

            foreach ($matches as $match) {
                $singleQuoted = $match[1] !== null;
                $literal = $singleQuoted ? $match[1] : $match[2];
                $keys[] = $singleQuoted
                    ? str_replace(['\\\\', "\\'"], ['\\', "'"], $literal)
                    : stripcslashes($literal);
            }
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

function backendTranslationJson(string $locale): array
{
    return json_decode(
        File::get(lang_path("{$locale}.json")),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function backendTranslationPlaceholders(string $value): array
{
    preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
    $placeholders = array_values(array_unique($matches[1]));
    sort($placeholders);

    return $placeholders;
}

it('covers every static backend translation key in every supported locale catalog', function () {
    $literalKeys = backendTranslationLiteralKeys();
    $english = backendTranslationJson('en');

    foreach (config('app.supported_locales') as $locale) {
        $catalog = backendTranslationJson($locale);

        expect(array_keys($catalog))
            ->toEqualCanonicalizing(array_keys($english))
            ->and(array_diff($literalKeys, array_keys($catalog)))
            ->toBeEmpty()
            ->and($catalog)
            ->each->toBeString()->not->toBeEmpty();
    }
});

it('preserves replacement placeholders in every backend translation', function () {
    foreach (config('app.supported_locales') as $locale) {
        foreach (backendTranslationJson($locale) as $key => $translation) {
            expect(backendTranslationPlaceholders($translation))
                ->toEqualCanonicalizing(backendTranslationPlaceholders($key));
        }
    }
});

it('provides matching framework authentication and validation catalogs', function () {
    foreach (['auth', 'passwords', 'pagination', 'validation'] as $catalog) {
        $english = require lang_path("en/{$catalog}.php");

        foreach (config('app.supported_locales') as $locale) {
            $localized = require lang_path("{$locale}/{$catalog}.php");
            $englishFlat = Arr::dot($english);
            $localizedFlat = Arr::dot($localized);

            expect(array_keys($localizedFlat))
                ->toEqualCanonicalizing(array_keys($englishFlat));

            foreach ($englishFlat as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                expect(backendTranslationPlaceholders((string) $localizedFlat[$key]))
                    ->toEqualCanonicalizing(backendTranslationPlaceholders($value));
            }
        }
    }

    expect(__('auth.failed', locale: 'de'))
        ->toBe('Diese Zugangsdaten stimmen nicht mit unseren Aufzeichnungen überein.')
        ->and(__('auth.failed', locale: 'en'))
        ->toBe('These credentials do not match our records.')
        ->and(__('validation.required', locale: 'de'))
        ->toBe(':attribute ist erforderlich.')
        ->and(__('validation.required', locale: 'en'))
        ->toBe('The :attribute field is required.');
});

it('ships actual draft translations instead of English catalog copies', function () {
    $english = backendTranslationJson('en');

    foreach (['pl', 'ro', 'hr', 'es', 'pt'] as $locale) {
        expect(backendTranslationJson($locale))
            ->not->toBe($english);

        foreach (['auth', 'passwords', 'pagination', 'validation'] as $catalog) {
            expect(require lang_path("{$locale}/{$catalog}.php"))
                ->not->toBe(require lang_path("en/{$catalog}.php"));
        }
    }
});
