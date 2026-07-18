#!/usr/bin/env node

import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { baseCompile } from '@intlify/message-compiler';
import ts from 'typescript';
import { parse as parseSfc } from '@vue/compiler-sfc';

const projectRoot = process.cwd();
const messagesRoot = path.join(projectRoot, 'resources/js/i18n/messages');
const vueRoot = path.join(projectRoot, 'resources/js');
const visibleAttributes = new Set([
    'title',
    'placeholder',
    'aria-label',
    'alt',
    'label',
    'description',
]);

const nodeTypes = {
    root: 0,
    element: 1,
    text: 2,
    interpolation: 5,
    attribute: 6,
    directive: 7,
};

const issues = [];

function displayPath(file) {
    return path.relative(projectRoot, file).split(path.sep).join('/');
}

async function filesBelow(directory, extension) {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = await Promise.all(
        entries.map(async (entry) => {
            const entryPath = path.join(directory, entry.name);

            if (entry.isDirectory()) {
                return filesBelow(entryPath, extension);
            }

            return entry.isFile() && entry.name.endsWith(extension)
                ? [entryPath]
                : [];
        }),
    );

    return files.flat().sort();
}

function issue(kind, file, line, column, message) {
    issues.push({
        kind,
        file: displayPath(file),
        line,
        column,
        message,
    });
}

function unwrapExpression(expression) {
    let current = expression;

    while (
        ts.isParenthesizedExpression(current) ||
        ts.isAsExpression(current) ||
        ts.isTypeAssertionExpression(current) ||
        ts.isSatisfiesExpression(current) ||
        ts.isNonNullExpression(current)
    ) {
        current = current.expression;
    }

    return current;
}

function propertyName(node) {
    if (
        ts.isIdentifier(node) ||
        ts.isStringLiteral(node) ||
        ts.isNumericLiteral(node) ||
        ts.isNoSubstitutionTemplateLiteral(node)
    ) {
        return node.text;
    }

    if (
        ts.isComputedPropertyName(node) &&
        (ts.isStringLiteral(node.expression) ||
            ts.isNoSubstitutionTemplateLiteral(node.expression))
    ) {
        return node.expression.text;
    }

    return null;
}

function sourceLocation(source, node) {
    const position = source.getLineAndCharacterOfPosition(
        node.getStart(source),
    );

    return {
        line: position.line + 1,
        column: position.character + 1,
    };
}

function translationKeyPaths(file, sourceText) {
    const source = ts.createSourceFile(
        file,
        sourceText,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const parseDiagnostics = source.parseDiagnostics ?? [];

    for (const diagnostic of parseDiagnostics) {
        const position =
            typeof diagnostic.start === 'number'
                ? source.getLineAndCharacterOfPosition(diagnostic.start)
                : { line: 0, character: 0 };
        issue(
            'message-ast',
            file,
            position.line + 1,
            position.character + 1,
            ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'),
        );
    }

    const exportAssignment = source.statements.find(
        (statement) =>
            ts.isExportAssignment(statement) && !statement.isExportEquals,
    );

    if (!exportAssignment) {
        issue(
            'message-ast',
            file,
            1,
            1,
            'Kein `export default` für den Nachrichtenkatalog gefunden.',
        );

        return new Map();
    }

    const root = unwrapExpression(exportAssignment.expression);

    if (!ts.isObjectLiteralExpression(root)) {
        const location = sourceLocation(source, exportAssignment.expression);
        issue(
            'message-ast',
            file,
            location.line,
            location.column,
            '`export default` muss ein statisches Objektliteral sein.',
        );

        return new Map();
    }

    const paths = new Map();

    function visitObject(object, parents) {
        for (const property of object.properties) {
            if (!ts.isPropertyAssignment(property)) {
                const location = sourceLocation(source, property);
                issue(
                    'message-ast',
                    file,
                    location.line,
                    location.column,
                    'Nachrichtenobjekte dürfen nur statische Property-Zuweisungen enthalten.',
                );
                continue;
            }

            const name = propertyName(property.name);

            if (name === null) {
                const location = sourceLocation(source, property.name);
                issue(
                    'message-ast',
                    file,
                    location.line,
                    location.column,
                    'Dynamische Nachrichten-Keys sind nicht zulässig.',
                );
                continue;
            }

            const currentPath = [...parents, name];
            const dottedPath = currentPath.join('.');
            const location = sourceLocation(source, property.name);

            if (name.includes('.')) {
                issue(
                    'message-key',
                    file,
                    location.line,
                    location.column,
                    `Nachrichten-Key \`${name}\` enthält einen Punkt und ist über Vue-i18n-Pfade nicht zuverlässig auflösbar. Verwende ein verschachteltes Objekt.`,
                );
            }

            if (paths.has(dottedPath)) {
                issue(
                    'message-ast',
                    file,
                    location.line,
                    location.column,
                    `Doppelter Nachrichten-Key \`${dottedPath}\`.`,
                );
            } else {
                paths.set(dottedPath, location);
            }

            const initializer = unwrapExpression(property.initializer);

            if (ts.isObjectLiteralExpression(initializer)) {
                visitObject(initializer, currentPath);
            } else if (
                ts.isStringLiteral(initializer) ||
                ts.isNoSubstitutionTemplateLiteral(initializer)
            ) {
                baseCompile(initializer.text, {
                    onError(error) {
                        issue(
                            'message-syntax',
                            file,
                            location.line,
                            location.column,
                            `Ungültige Vue-i18n-Nachricht \`${dottedPath}\`: ${error.message}`,
                        );
                    },
                });
            }
        }
    }

    visitObject(root, []);

    return paths;
}

function counterpartFor(file) {
    const basename = path.basename(file);

    if (basename === 'de.ts') {
        return path.join(path.dirname(file), 'en.ts');
    }

    if (basename === 'en.ts') {
        return path.join(path.dirname(file), 'de.ts');
    }

    if (basename.endsWith('-de.ts')) {
        return path.join(
            path.dirname(file),
            basename.replace(/-de\.ts$/u, '-en.ts'),
        );
    }

    if (basename.endsWith('-en.ts')) {
        return path.join(
            path.dirname(file),
            basename.replace(/-en\.ts$/u, '-de.ts'),
        );
    }

    return null;
}

async function checkMessageCatalogs() {
    const files = await filesBelow(messagesRoot, '.ts');
    const fileSet = new Set(files);
    const pairs = new Map();

    for (const file of files) {
        const counterpart = counterpartFor(file);

        if (counterpart === null) {
            continue;
        }

        if (!fileSet.has(counterpart)) {
            issue(
                'message-pair',
                file,
                1,
                1,
                `Sprachpartner \`${displayPath(counterpart)}\` fehlt.`,
            );
            continue;
        }

        const pairKey = [file, counterpart].sort().join('\0');
        pairs.set(pairKey, [file, counterpart].sort());
    }

    for (const [leftFile, rightFile] of pairs.values()) {
        const [leftSource, rightSource] = await Promise.all([
            readFile(leftFile, 'utf8'),
            readFile(rightFile, 'utf8'),
        ]);
        const leftPaths = translationKeyPaths(leftFile, leftSource);
        const rightPaths = translationKeyPaths(rightFile, rightSource);

        for (const [key, location] of leftPaths) {
            if (!rightPaths.has(key)) {
                issue(
                    'message-key',
                    leftFile,
                    location.line,
                    location.column,
                    `Key \`${key}\` fehlt in \`${displayPath(rightFile)}\`.`,
                );
            }
        }

        for (const [key, location] of rightPaths) {
            if (!leftPaths.has(key)) {
                issue(
                    'message-key',
                    rightFile,
                    location.line,
                    location.column,
                    `Key \`${key}\` fehlt in \`${displayPath(leftFile)}\`.`,
                );
            }
        }
    }
}

function normalizedText(value) {
    return value.replace(/\s+/gu, ' ').trim();
}

function isAllowedVisibleText(value) {
    const text = normalizedText(value);

    if (text === '') {
        return true;
    }

    if (/^[\p{P}\p{S}\p{Z}\p{M}]+$/u.test(text)) {
        return true;
    }

    if (/^#?ER(?:[-\s]?\d+)?$/u.test(text)) {
        return true;
    }

    // Stable identifiers, locale codes and URI examples are technical values,
    // not translatable prose.
    if (
        /^#[A-Z][A-Z0-9]*-$/u.test(text) ||
        /^·?\s*[A-Z]{2}$/u.test(text) ||
        /^[a-z][a-z0-9+.-]*:\/\/\S*$/u.test(text) ||
        /^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/u.test(text)
    ) {
        return true;
    }

    const withoutBrands = text
        .replace(/\b(?:Erin|erin|Laravel|PHP|ER)\b/gu, '')
        .replace(/[\d\s\p{P}\p{S}\p{M}]/gu, '');

    if (withoutBrands === '') {
        return true;
    }

    const keyboardToken =
        '(?:Ctrl|Control|Alt|Option|Shift|Cmd|Command|Enter|Return|Esc|Escape|Tab|Space|Backspace|Delete|Home|End|PgUp|PgDn|Arrow(?:Up|Down|Left|Right)|F(?:[1-9]|1[0-2])|[⌘⌥⇧⌃]|[A-Z0-9])';
    const keyboardPattern = new RegExp(
        `^(?=.*(?:[+⌘⌥⇧⌃]|Ctrl|Control|Alt|Option|Shift|Cmd|Command|Enter|Return|Esc|Escape|Tab|Space|Backspace|Delete))${keyboardToken}(?:\\s*(?:[+·/-]|\\s)\\s*${keyboardToken})*$`,
        'u',
    );

    return keyboardPattern.test(text);
}

function staticStringExpression(expression) {
    if (!expression) {
        return null;
    }

    const source = ts.createSourceFile(
        'template-expression.ts',
        `const __value = (${expression});`,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    const declaration = source.statements
        .filter(ts.isVariableStatement)
        .flatMap((statement) => statement.declarationList.declarations)
        .at(0);

    if (!declaration?.initializer) {
        return null;
    }

    const initializer = unwrapExpression(declaration.initializer);

    if (
        ts.isStringLiteral(initializer) ||
        ts.isNoSubstitutionTemplateLiteral(initializer)
    ) {
        return initializer.text;
    }

    return null;
}

function visibleValueLocation(location, value) {
    const source = location.source ?? value;
    const leadingWhitespace = source.match(/^\s*/u)?.[0] ?? '';
    const lineBreaks = leadingWhitespace.match(/\n/gu)?.length ?? 0;
    const charactersAfterLastBreak =
        lineBreaks === 0
            ? leadingWhitespace.length
            : leadingWhitespace.length -
              leadingWhitespace.lastIndexOf('\n') -
              1;

    return {
        line: location.start.line + lineBreaks,
        column:
            lineBreaks === 0
                ? location.start.column + charactersAfterLastBreak
                : charactersAfterLastBreak + 1,
    };
}

function inspectVisibleValue(file, location, kind, value) {
    const text = normalizedText(value);

    if (isAllowedVisibleText(text)) {
        return;
    }

    const absolute = visibleValueLocation(location, value);
    issue(
        kind,
        file,
        absolute.line,
        absolute.column,
        `Statischer nutzersichtbarer Text: ${JSON.stringify(text)}`,
    );
}

function inspectTemplateNode(file, node) {
    if (node.type === nodeTypes.text) {
        inspectVisibleValue(file, node.loc, 'vue-text', node.content);

        return;
    }

    if (node.type === nodeTypes.interpolation) {
        const value = staticStringExpression(node.content?.content);

        if (value !== null) {
            inspectVisibleValue(file, node.loc, 'vue-text', value);
        }

        return;
    }

    if (node.type === nodeTypes.element) {
        for (const property of node.props ?? []) {
            if (
                property.type === nodeTypes.attribute &&
                visibleAttributes.has(property.name) &&
                property.value
            ) {
                inspectVisibleValue(
                    file,
                    property.value.loc,
                    `vue-attr:${property.name}`,
                    property.value.content,
                );
                continue;
            }

            if (
                property.type === nodeTypes.directive &&
                property.name === 'bind' &&
                property.arg?.type === 4 &&
                property.arg.isStatic &&
                visibleAttributes.has(property.arg.content)
            ) {
                const value = staticStringExpression(property.exp?.content);

                if (value !== null) {
                    inspectVisibleValue(
                        file,
                        property.exp?.loc ?? property.loc,
                        `vue-attr:${property.arg.content}`,
                        value,
                    );
                }
            }
        }
    }

    if (node.type === nodeTypes.root || node.type === nodeTypes.element) {
        for (const child of node.children ?? []) {
            inspectTemplateNode(file, child);
        }
    }
}

async function checkVueTemplates() {
    const files = await filesBelow(vueRoot, '.vue');

    for (const file of files) {
        const source = await readFile(file, 'utf8');
        const { descriptor, errors } = parseSfc(source, {
            filename: displayPath(file),
        });

        for (const error of errors) {
            const location = error.loc?.start ?? { line: 1, column: 1 };
            issue(
                'vue-ast',
                file,
                location.line,
                location.column,
                typeof error === 'string' ? error : error.message,
            );
        }

        if (descriptor.template?.ast) {
            inspectTemplateNode(file, descriptor.template.ast);
        }
    }
}

await Promise.all([checkMessageCatalogs(), checkVueTemplates()]);

issues.sort(
    (left, right) =>
        left.file.localeCompare(right.file) ||
        left.line - right.line ||
        left.column - right.column ||
        left.kind.localeCompare(right.kind) ||
        left.message.localeCompare(right.message),
);

if (issues.length === 0) {
    console.log(
        'i18n-Check erfolgreich: Nachrichten-Keys und Vue-Templates sind konsistent.',
    );
    process.exit(0);
}

console.error(
    `i18n-Check fehlgeschlagen: ${issues.length} Problem(e) gefunden.`,
);

for (const current of issues) {
    console.error(
        `- [${current.kind}] ${current.file}:${current.line}:${current.column} ${current.message}`,
    );
}

process.exit(1);
