import stylistic from '@stylistic/eslint-plugin';
import { defineConfig } from 'eslint/config';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

export default defineConfig(
    vue.configs['flat/essential'],
    tseslint.configs.recommended.map((config) => ({
        ...config,
        ...(config.files ? { files: [...config.files, '**/*.vue'] } : {}),
    })),
    vue.configs['flat/base'],
    {
        files: ['**/*.vue'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: {
                    ...tseslint.parser,
                    js: 'espree',
                    jsx: 'espree',
                    ts: tseslint.parser,
                    tsx: tseslint.parser,
                },
                projectService: true,
                ecmaVersion: 2024,
                ecmaFeatures: { jsx: false },
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            'vue/block-lang': ['error', { script: { lang: ['ts'], allowNoLang: false } }],
        },
    },
    {
        files: ['**/*.js', '**/*.jsx', '**/*.cjs', '**/*.mjs'],
        ...tseslint.configs.disableTypeChecked,
    },
    {
        files: ['**/*.ts', '**/*.tsx', '**/*.mts'],
        languageOptions: {
            parser: tseslint.parser,
            parserOptions: {
                projectService: true,
                extraFileExtensions: ['.vue'],
            },
        },
    },
    {
        files: ['**/*.ts', '**/*.tsx', '**/*.mts', '**/*.vue'],
        rules: {
            '@typescript-eslint/no-unsafe-argument': 'off',
            '@typescript-eslint/no-unsafe-assignment': 'off',
            '@typescript-eslint/no-unsafe-return': 'off',
            '@typescript-eslint/no-unsafe-call': 'off',
            '@typescript-eslint/no-unsafe-member-access': 'off',
        },
    },
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: ['builtin', 'external', 'internal', 'parent', 'sibling', 'index'],
                    // alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'vite.config.ts',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
        },
    },
);
