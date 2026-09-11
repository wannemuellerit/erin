import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import vm from 'node:vm';
import ts from 'typescript';

const executeFile = promisify(execFile);
const root = process.cwd();
const messagesDir = path.join(root, 'resources/js/i18n/messages');
const langDir = path.join(root, 'lang');
const locales = ['pl', 'ro', 'hr', 'es', 'pt'];
const sources = [
    'en.ts',
    'auth-en.ts',
    'candidate-en.ts',
    'dashboard-en.ts',
    'employer-en.ts',
    'operations-en.ts',
    'status-en.ts',
    'admin-en.ts',
    'product-components-en.ts',
];
const phpSources = [
    'auth.php',
    'pagination.php',
    'passwords.php',
    'validation.php',
];
const cachePath = path.join(root, '.localization-cache.json');
const modelConfigPath = process.env.ERIN_OFFLINE_MODELS;
const pythonPath = process.env.ERIN_OFFLINE_PYTHONPATH;

if (!modelConfigPath || !pythonPath) {
    throw new Error(
        'ERIN_OFFLINE_MODELS and ERIN_OFFLINE_PYTHONPATH are required. The generator never sends catalog text to a remote service.',
    );
}

const modelConfig = JSON.parse(await fs.readFile(modelConfigPath, 'utf8'));
let cache = {};

try {
    cache = JSON.parse(await fs.readFile(cachePath, 'utf8'));
} catch {
    // The cache is an optional, ignored build artifact.
}

const readTypescriptObject = async (file) => {
    const source = await fs.readFile(path.join(messagesDir, file), 'utf8');
    const javascript = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const context = { exports: {} };
    vm.runInNewContext(javascript, context, { filename: file });

    return JSON.parse(JSON.stringify(context.exports.default));
};

const readPhpArray = async (locale, file) => {
    const absolutePath = path.join(langDir, locale, file);
    const php = `$value = require ${JSON.stringify(absolutePath)}; echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);`;
    const { stdout } = await executeFile('php', ['-r', php], {
        maxBuffer: 4 * 1024 * 1024,
    });

    return JSON.parse(stdout);
};

const protectionPattern = () =>
    /php artisan [A-Za-z0-9:_-]+(?:\s+--[A-Za-z0-9_-]+)*|--[A-Za-z0-9_-]+|\{\{[^}]+\}\}|\{[^}]+\}|<[^>]+>|https?:\/\/\S+|:[A-Za-z_][A-Za-z0-9_]*/g;
const shouldTranslate = (value) =>
    /[A-Za-zÄÖÜäöüß]{2}/.test(value.replace(protectionPattern(), '')) &&
    !/^(https?:|[A-Z]{2,5}|[a-z0-9._/-]+:[a-z0-9._/-]+)$/i.test(value);
const protect = (value) => {
    const tokens = [];
    const text = value.replace(
        protectionPattern(),
        (token) => `<x${tokens.push(token) - 1}/>`,
    );

    return { text, tokens };
};
const restore = (value, tokens) =>
    value.replace(
        /<\s*x\s*(\d+)\s*\/?\s*>/gi,
        (match, index) => tokens[Number(index)] ?? match,
    );
const assertTranslationQuality = (locale, source, localized, label) => {
    const missingTokens = protect(source).tokens.filter(
        (token) => !localized.includes(token),
    );

    if (
        localized.trim() === '' ||
        missingTokens.length > 0 ||
        localized.length > source.length * 10 + 500
    ) {
        throw new Error(
            `Offline translation quality guard failed for ${locale}: ${label}`,
        );
    }
};
const restoreDroppedPlaceholders = (localized, tokens) => {
    const missing = tokens.filter((token) => !localized.includes(token));

    if (
        missing.length === 0 ||
        missing.some(
            (token) =>
                !/^\{\{[^}]+\}\}$|^\{[^}]+\}$|^:[A-Za-z_][A-Za-z0-9_]*$|^--[A-Za-z0-9_-]+$|^php artisan |^https?:\/\//.test(
                    token,
                ),
        )
    ) {
        return localized;
    }

    return `${localized.trim()} ${missing.join(' ')}`;
};

const pairs = new Map();
function collectPairs(english, german) {
    if (typeof english === 'string') {
        if (english.includes(' | ')) {
            const englishForms = english.split(' | ');
            const germanForms =
                typeof german === 'string' ? german.split(' | ') : [];

            englishForms.forEach((form, index) => {
                collectPairs(
                    form,
                    germanForms[index] ?? germanForms[0] ?? form,
                );
            });

            return;
        }

        const candidate = typeof german === 'string' ? german : english;
        const existing = pairs.get(english);
        const hasTokenParity = (value) =>
            JSON.stringify(protect(value).tokens) ===
            JSON.stringify(protect(english).tokens);

        if (
            existing === undefined ||
            (!hasTokenParity(existing) && hasTokenParity(candidate))
        ) {
            pairs.set(english, candidate);
        }

        return;
    }

    if (!english || typeof english !== 'object') {
        return;
    }

    for (const [key, value] of Object.entries(english)) {
        collectPairs(value, german?.[key]);
    }
}

const typescriptCatalogs = [];

for (const source of sources) {
    const germanSource =
        source === 'en.ts' ? 'de.ts' : source.replace(/-en\.ts$/, '-de.ts');
    const [english, german] = await Promise.all([
        readTypescriptObject(source),
        readTypescriptObject(germanSource),
    ]);
    typescriptCatalogs.push({ source, english });
    collectPairs(english, german);
}

const [englishJson, germanJson] = await Promise.all([
    fs.readFile(path.join(langDir, 'en.json'), 'utf8').then(JSON.parse),
    fs.readFile(path.join(langDir, 'de.json'), 'utf8').then(JSON.parse),
]);
collectPairs(englishJson, germanJson);

const phpCatalogs = [];

for (const source of phpSources) {
    const [english, german] = await Promise.all([
        readPhpArray('en', source),
        readPhpArray('de', source),
    ]);
    phpCatalogs.push({ source, english });
    collectPairs(english, german);
}

async function populateLocale(locale) {
    const configuration = modelConfig[locale];

    if (
        !configuration ||
        !['en', 'de'].includes(configuration.source) ||
        typeof configuration.model !== 'string'
    ) {
        throw new Error(
            `Offline model configuration is invalid for ${locale}.`,
        );
    }

    for (const [english, german] of pairs.entries()) {
        const key = `${locale}\0${english}`;

        if (typeof cache[key] !== 'string') {
            continue;
        }

        try {
            assertTranslationQuality(
                locale,
                configuration.source === 'de' ? german : english,
                cache[key],
                english,
            );
        } catch {
            delete cache[key];
        }
    }

    const pending = [...pairs.entries()]
        .filter(
            ([english]) =>
                shouldTranslate(english) && !cache[`${locale}\0${english}`],
        )
        .map(([english, german]) => ({
            english,
            source: configuration.source === 'de' ? german : english,
        }));

    if (pending.length === 0) {
        return;
    }

    const protectedValues = pending.map(({ source }) => protect(source));
    const temporaryDirectory = await fs.mkdtemp(
        path.join(os.tmpdir(), 'erin-localize-'),
    );
    const inputPath = path.join(temporaryDirectory, 'input.json');
    const outputPath = path.join(temporaryDirectory, 'output.json');
    await fs.writeFile(
        inputPath,
        JSON.stringify(protectedValues.map(({ text }) => text)),
    );

    await executeFile(
        'python3',
        [
            path.join(root, 'scripts/offline-translate.py'),
            configuration.model,
            inputPath,
            outputPath,
        ],
        {
            env: {
                ...process.env,
                PYTHONPATH: pythonPath,
            },
            maxBuffer: 4 * 1024 * 1024,
            timeout: 30 * 60 * 1000,
        },
    );
    const translated = JSON.parse(await fs.readFile(outputPath, 'utf8'));

    if (!Array.isArray(translated) || translated.length !== pending.length) {
        throw new Error(
            `Offline translator returned an invalid ${locale} batch.`,
        );
    }

    translated.forEach((value, index) => {
        let localized = restore(String(value), protectedValues[index].tokens);
        localized = restoreDroppedPlaceholders(
            localized,
            protectedValues[index].tokens,
        );
        assertTranslationQuality(
            locale,
            pending[index].source,
            localized,
            pending[index].english,
        );

        cache[`${locale}\0${pending[index].english}`] = localized;
    });
    await fs.writeFile(cachePath, JSON.stringify(cache));
    await fs.rm(temporaryDirectory, { recursive: true, force: true });
}

for (const locale of locales) {
    await populateLocale(locale);
}

async function translateTree(value, locale) {
    if (typeof value === 'string') {
        if (value.includes(' | ')) {
            return (
                await Promise.all(
                    value
                        .split(' | ')
                        .map((form) => translateTree(form, locale)),
                )
            ).join(' | ');
        }

        if (!shouldTranslate(value)) {
            return value;
        }

        const translated = cache[`${locale}\0${value}`];

        if (typeof translated !== 'string' || translated === '') {
            throw new Error(
                `Missing offline translation for ${locale}: ${value}`,
            );
        }

        assertTranslationQuality(locale, value, translated, value);

        return translated;
    }

    if (Array.isArray(value)) {
        return Promise.all(value.map((item) => translateTree(item, locale)));
    }

    if (value && typeof value === 'object') {
        const result = {};
        await Promise.all(
            Object.entries(value).map(async ([key, child]) => {
                result[key] = await translateTree(child, locale);
            }),
        );

        return result;
    }

    return value;
}

const phpExport = (value, depth = 0) => {
    if (typeof value === 'string') {
        return `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (value === null) {
        return 'null';
    }

    const indent = '    '.repeat(depth);
    const childIndent = '    '.repeat(depth + 1);
    const entries = Object.entries(value);
    const list = Array.isArray(value);

    if (entries.length === 0) {
        return '[]';
    }

    return `[\n${entries
        .map(([key, child]) => {
            const prefix = list ? '' : `${phpExport(key)} => `;

            return `${childIndent}${prefix}${phpExport(child, depth + 1)},`;
        })
        .join('\n')}\n${indent}]`;
};

for (const { source, english } of typescriptCatalogs) {
    for (const locale of locales) {
        const translated = await translateTree(english, locale);
        const target =
            source === 'en.ts'
                ? `${locale}.ts`
                : source.replace(/-en\.ts$/, `-${locale}.ts`);
        await fs.writeFile(
            path.join(messagesDir, target),
            `export default ${JSON.stringify(translated, null, 4)};\n`,
        );
        process.stdout.write(`${target}\n`);
    }
}

for (const locale of locales) {
    const translated = await translateTree(englishJson, locale);
    await fs.writeFile(
        path.join(langDir, `${locale}.json`),
        `${JSON.stringify(translated, null, 4)}\n`,
    );
    process.stdout.write(`lang/${locale}.json\n`);
}

for (const { source, english } of phpCatalogs) {
    for (const locale of locales) {
        const translated = await translateTree(english, locale);
        const targetDirectory = path.join(langDir, locale);
        await fs.mkdir(targetDirectory, { recursive: true });
        await fs.writeFile(
            path.join(targetDirectory, source),
            `<?php\n\nreturn ${phpExport(translated)};\n`,
        );
        process.stdout.write(`lang/${locale}/${source}\n`);
    }
}

await fs.writeFile(cachePath, JSON.stringify(cache));
