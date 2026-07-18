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

it('covers every static backend translation key in matching German and English catalogs', function () {
    $literalKeys = backendTranslationLiteralKeys();
    $german = backendTranslationJson('de');
    $english = backendTranslationJson('en');

    expect(array_keys($german))
        ->toEqualCanonicalizing(array_keys($english))
        ->and(array_diff($literalKeys, array_keys($german)))
        ->toBeEmpty()
        ->and(array_diff($literalKeys, array_keys($english)))
        ->toBeEmpty()
        ->and($german)
        ->each->toBeString()->not->toBeEmpty()
        ->and($english)
        ->each->toBeString()->not->toBeEmpty();
});

it('preserves replacement placeholders in every backend translation', function () {
    foreach (['de', 'en'] as $locale) {
        foreach (backendTranslationJson($locale) as $key => $translation) {
            expect(backendTranslationPlaceholders($translation))
                ->toEqualCanonicalizing(backendTranslationPlaceholders($key));
        }
    }
});

it('provides matching framework authentication and validation catalogs', function () {
    foreach (['auth', 'passwords', 'pagination', 'validation'] as $catalog) {
        $german = require lang_path("de/{$catalog}.php");
        $english = require lang_path("en/{$catalog}.php");

        expect(array_keys(Arr::dot($german)))
            ->toEqualCanonicalizing(array_keys(Arr::dot($english)));
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
