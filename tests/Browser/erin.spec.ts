import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const password = 'password';

const demoAccounts = [
    { id: 'superadmin', email: 'admin@wannemueller.dev' },
    { id: 'mueller', email: 'unternehmen.mueller@wannemueller.dev' },
    {
        id: 'rheincargo',
        email: 'unternehmen.rheincargo@wannemueller.dev',
    },
    ...Array.from({ length: 10 }, (_, index) => {
        const id = `candidate${String(index + 1).padStart(2, '0')}`;

        return { id, email: `${id}@wannemueller.dev` };
    }),
] as const;

const accounts = {
    admin: {
        email: 'admin@wannemueller.dev',
        name: 'Wannemüller Admin',
    },
    candidate: {
        email: 'candidate01@wannemueller.dev',
        name: 'Anna Kowalska',
    },
    company: {
        email: 'unternehmen.mueller@wannemueller.dev',
        name: 'Marie Müller',
    },
    support: {
        email: 'support.e2e@wannemueller.dev',
        name: 'Erin E2E Support',
    },
    onboardingCandidate: {
        email: 'onboarding.candidate@wannemueller.dev',
    },
    onboardingCompany: {
        email: 'onboarding.company@wannemueller.dev',
    },
} as const;

async function submitLogin(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('E-Mail-Adresse', { exact: true }).fill(email);
    await page.getByLabel('Passwort', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();
}

async function logIn(page: Page, email: string): Promise<void> {
    await submitLogin(page, email);
    await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
    await expect(page.getByRole('main')).toBeVisible();
}

async function expectAccessibleAppChrome(page: Page): Promise<void> {
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.locator('header')).toBeVisible();

    const search = page.getByRole('searchbox', {
        name: 'Plattform durchsuchen',
    });
    const notifications = page.getByRole('button', {
        name: 'Benachrichtigungen öffnen',
    });
    const sidebarToggle = page.getByRole('button', {
        name: 'Navigation öffnen',
    });

    await expect(search).toBeVisible();
    await expect(search).toHaveAccessibleName('Plattform durchsuchen');
    await expect(notifications).toBeVisible();
    await expect(notifications).toHaveAccessibleName(
        'Benachrichtigungen öffnen',
    );
    await expect(sidebarToggle).toBeVisible();
    await expect(sidebarToggle).toHaveAccessibleName('Navigation öffnen');
}

async function expectNoSeriousAccessibilityViolations(
    page: Page,
    include?: string,
): Promise<void> {
    let audit = new AxeBuilder({ page }).withTags([
        'wcag2a',
        'wcag2aa',
        'wcag21a',
        'wcag21aa',
    ]);

    if (include) {
        audit = audit.include(include);
    }

    const results = await audit.analyze();
    const violations = results.violations
        .filter(
            (violation) =>
                violation.impact === 'critical' ||
                violation.impact === 'serious',
        )
        .map((violation) => ({
            id: violation.id,
            impact: violation.impact,
            help: violation.help,
            targets: violation.nodes.flatMap((node) => node.target),
        }));

    expect(
        violations,
        `Axe hat schwerwiegende WCAG-Verstöße gefunden:\n${JSON.stringify(violations, null, 2)}`,
    ).toEqual([]);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
    await expect
        .poll(
            () =>
                page.evaluate(() => {
                    const root = document.documentElement;
                    const body = document.body;
                    const widestContent = Math.max(
                        root.scrollWidth,
                        body?.scrollWidth ?? 0,
                    );

                    return widestContent - root.clientWidth;
                }),
            {
                message:
                    'Die Seite darf den mobilen Viewport horizontal nicht überschreiten.',
            },
        )
        .toBeLessThanOrEqual(1);
}

test.describe('Login und Rollenbereiche', () => {
    test('zeigt alle Demo-Zugänge und setzt jeden Zugang barrierearm ein', async ({
        page,
    }) => {
        const vueWarnings: string[] = [];
        page.on('console', (message) => {
            if (
                message.type() === 'warning' &&
                message.text().includes('[Vue warn]')
            ) {
                vueWarnings.push(message.text());
            }
        });

        await page.goto('/login');

        await expect(page.getByRole('main')).toHaveCount(1);
        await expect(page.getByRole('complementary')).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Schön, Sie wiederzusehen',
            }),
        ).toBeVisible();

        const email = page.getByLabel('E-Mail-Adresse', { exact: true });
        const loginPassword = page.getByLabel('Passwort', { exact: true });
        const insert = page.getByTestId('insert-demo-account-rheincargo');
        const submit = page.getByTestId('login-button');

        await expect(
            page.getByText('Demo-Zugänge', { exact: true }),
        ).toBeVisible();
        await expect(page.getByTestId('demo-account-picker')).toBeVisible();
        await expect(page.getByTestId('demo-password')).toHaveText(password);
        await expect(
            page.locator('li[data-test^="demo-account-"]'),
        ).toHaveCount(13);
        await expect(
            page.locator('[data-test^="insert-demo-account-"]'),
        ).toHaveCount(13);

        for (const demoAccount of demoAccounts) {
            await expect(
                page.getByText(demoAccount.email, { exact: true }),
            ).toBeVisible();
            await page
                .getByTestId(`insert-demo-account-${demoAccount.id}`)
                .click();
            await expect(email).toHaveValue(demoAccount.email);
            await expect(loginPassword).toHaveValue(password);
        }

        await expect(email).toHaveAccessibleName('E-Mail-Adresse');
        await expect(loginPassword).toHaveAccessibleName('Passwort');
        await expect(insert).toHaveAccessibleName(
            'Zugangsdaten für Daniel Schneider einsetzen',
        );
        await expect(submit).toHaveAccessibleName('Anmelden');
        await expectNoSeriousAccessibilityViolations(page);

        await insert.click();

        await expect(email).toHaveValue(
            'unternehmen.rheincargo@wannemueller.dev',
        );
        await expect(loginPassword).toHaveValue(password);
        expect(vueWarnings).toEqual([]);
    });

    test('meldet den Superadmin an und öffnet die Admin-Navigation', async ({
        page,
    }) => {
        await logIn(page, accounts.admin.email);
        await expectAccessibleAppChrome(page);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Admin Cockpit',
            }),
        ).toBeVisible();
        await expect(page.getByTestId('sidebar-menu-button')).toContainText(
            accounts.admin.name,
        );

        for (const name of [
            'Übersicht',
            'Benutzer',
            'Unternehmen',
            'Dokumentprüfung',
            'Visa-Fälle',
            'Support',
            'Paket & Abrechnung',
            'Referrals',
            'Audit Log',
            'System',
            'Einstellungen',
        ]) {
            await expect(
                page.getByRole('link', { name, exact: true }),
            ).toBeVisible();
        }

        await expectNoSeriousAccessibilityViolations(page);

        await page
            .getByRole('link', {
                name: 'Paket & Abrechnung',
                exact: true,
            })
            .click();
        await expect(page).toHaveURL(/\/admin\/billing$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Abrechnung & Stripe',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Stripe-Konfigurationsstatus',
            }),
        ).toBeVisible();
        await expect(page.getByText(/sk_(?:test|live)_/)).toHaveCount(0);
        await expect(page.getByText(/whsec_/)).toHaveCount(0);
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByRole('link', { name: 'Benutzer', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/users$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Benutzerverwaltung',
            }),
        ).toBeVisible();
    });

    test('zeigt dem Superadmin Uploadlimits, Dashboard-Anzeigen und Nutzerhistorien', async ({
        page,
    }) => {
        await logIn(page, accounts.admin.email);
        await page.goto('/admin/settings');

        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Uploads & Speicher',
            }),
        ).toBeVisible();
        await expect(
            page.getByLabel('Maximale Dateigröße in MB'),
        ).toBeVisible();
        await expect(
            page.getByLabel('Speicherlimit je Nutzer in MB'),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Dashboard-Anzeige',
            }),
        ).toBeVisible();
        await expect(page.getByLabel('Zielgruppe')).toBeVisible();

        await page.goto('/admin/users');
        await expect(
            page.getByRole('link', { name: 'Aktivitätshistorie' }).first(),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);
    });

    test('meldet eine Fachkraft an und zeigt ausschließlich ihre Navigation', async ({
        page,
    }) => {
        await logIn(page, accounts.candidate.email);
        await expectAccessibleAppChrome(page);
        await expect(page.getByTestId('sidebar-menu-button')).toContainText(
            accounts.candidate.name,
        );
        await expect(
            page.getByRole('link', {
                name: 'Passende Jobs',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Mein Profil', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Benutzer', exact: true }),
        ).toHaveCount(0);
        await expectNoSeriousAccessibilityViolations(page);

        await page
            .getByRole('link', { name: 'Passende Jobs', exact: true })
            .click();

        await expect(page).toHaveURL(/\/candidate\/jobs$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Passende Jobs für dich',
            }),
        ).toBeVisible();
    });

    test('meldet ein Unternehmen an und öffnet dessen Recruiting-Bereich', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await expectAccessibleAppChrome(page);
        await expect(page.getByTestId('sidebar-menu-button')).toContainText(
            accounts.company.name,
        );
        await expect(
            page.getByText('In Prüfung', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: 'Fachkräfte',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: 'Stellenanzeigen',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Benutzer', exact: true }),
        ).toHaveCount(0);
        await expectNoSeriousAccessibilityViolations(page);

        await page
            .getByRole('link', {
                name: 'Stellenanzeigen',
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/employer\/jobs$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Stellenanzeigen',
            }),
        ).toBeVisible();
    });

    test('rendert die Benachrichtigungseinstellungen mit Browser-Push und Erinnerungen', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await page.goto('/settings/notifications');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Benachrichtigungen',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('region', {
                name: 'Browser-Push auf diesem Gerät',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Erinnerungen',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: 'Einstellungen speichern',
            }),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);
    });

    test('lehnt unbekannte Konten und falsche Passwörter gleichförmig ab', async ({
        page,
    }) => {
        const attempts = [
            {
                email: 'nicht-vorhanden@wannemueller.dev',
                password: 'Falsch-2026!',
            },
            {
                email: 'candidate10@wannemueller.dev',
                password: 'Falsch-2026!',
            },
        ];

        for (const attempt of attempts) {
            await page.goto('/login');
            await page
                .getByLabel('E-Mail-Adresse', { exact: true })
                .fill(attempt.email);
            await page
                .getByLabel('Passwort', { exact: true })
                .fill(attempt.password);
            await page.getByTestId('login-button').click();

            await expect(page).toHaveURL(/\/login$/);
            await expect(
                page.getByText(
                    'Diese Zugangsdaten stimmen nicht mit unseren Aufzeichnungen überein.',
                    { exact: true },
                ),
            ).toBeVisible();
            await expect(page.getByRole('main')).toHaveCount(1);
            await expect(page.getByTestId('sidebar-menu-button')).toHaveCount(
                0,
            );
        }
    });

    test('leitet Gäste von sämtlichen Rollenbereichen zum Login um', async ({
        page,
    }) => {
        for (const protectedPath of [
            '/admin/users',
            '/employer/jobs',
            '/candidate/profile',
        ]) {
            await page.goto(protectedPath);
            await expect(page).toHaveURL(/\/login$/);
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: 'Schön, Sie wiederzusehen',
                }),
            ).toBeVisible();
        }
    });

    test('blockiert rollenfremde Direktzugriffe eines Unternehmens', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);

        for (const forbiddenPath of ['/admin/users', '/candidate/profile']) {
            const response = await page.goto(forbiddenPath);
            expect(response?.status()).toBe(403);
        }
    });

    test('blockiert rollenfremde Direktzugriffe einer Fachkraft', async ({
        page,
    }) => {
        await logIn(page, accounts.candidate.email);

        for (const forbiddenPath of ['/admin/users', '/employer/jobs']) {
            const response = await page.goto(forbiddenPath);
            expect(response?.status()).toBe(403);
        }
    });
});

test.describe('Onboarding und Abrechnung', () => {
    test('führt eine Fachkraft vom Onboarding in ihr Profil', async ({
        page,
    }) => {
        await submitLogin(page, accounts.onboardingCandidate.email);
        await expect(page).toHaveURL(/\/onboarding$/);
        await expect(page.getByTestId('candidate-onboarding')).toBeVisible();
        await page
            .getByRole('button', { name: 'Einrichtung abschließen' })
            .hover();
        await expectNoSeriousAccessibilityViolations(page);

        await page.locator('#occupation_id').selectOption({ index: 1 });
        await page.getByLabel('Wunschposition *').fill('Elektriker');
        await page.getByLabel('Jahre Berufserfahrung *').fill('5');
        await page.getByLabel('Telefonnummer *').fill('+49 170 1234567');
        await page.getByLabel('Aktuelles Land (ISO) *').fill('PL');
        await page.getByLabel('Aktuelle Stadt *').fill('Wrocław');
        await page
            .getByPlaceholder(
                'Beschreibe deine Erfahrung, Stärken und gewünschte Tätigkeit.',
            )
            .fill(
                'Ich bin ausgebildeter Elektriker mit fünf Jahren Berufserfahrung und möchte langfristig in einem deutschen Industriebetrieb arbeiten.',
            );
        await page
            .getByRole('button', { name: 'Einrichtung abschließen' })
            .click();

        await expect(page).toHaveURL(/\/candidate\/profile$/);
    });

    test('führt ein Unternehmen bis zur Stripe-Abrechnungsseite', async ({
        page,
    }) => {
        await submitLogin(page, accounts.onboardingCompany.email);
        await expect(page).toHaveURL(/\/onboarding$/);
        await expect(page.getByTestId('company-onboarding')).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByRole('button', { name: /Basic/ }).click();
        await page
            .getByLabel('Rechtlicher Firmenname *')
            .fill('E2E Onboarding GmbH');
        await page
            .getByLabel('Rechnungs-E-Mail *')
            .fill('rechnung.e2e@wannemueller.dev');
        await page.getByLabel('Branche *').fill('Elektrotechnik');
        await page.getByLabel('Mitarbeitende *').fill('25');
        await page.getByLabel('Straße *').fill('Teststraße 42');
        await page.getByLabel('Postleitzahl *').fill('40210');
        await page.getByLabel('Stadt *').fill('Düsseldorf');
        await page.getByLabel('Land (ISO) *').fill('DE');
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();

        await expect(page).toHaveURL(/\/employer\/billing$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Paket & Abrechnung',
            }),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);
    });
});

test.describe('Support und Mandantentrennung', () => {
    test('zeigt die Supportansicht dauerhaft schreibgeschützt', async ({
        page,
    }) => {
        await logIn(page, accounts.support.email);
        await page.goto('/admin/support');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Support' }),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);

        await page
            .getByPlaceholder('Grund, mindestens 10 Zeichen …')
            .fill('Reproduzierbarer schreibgeschützter E2E-Supportfall');
        await page.getByRole('button', { name: 'Ansicht öffnen' }).click();

        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        const supportBanner = page.getByRole('status');
        await expect(supportBanner).toContainText('Supportansicht');
        await expect(supportBanner).toContainText(accounts.support.name);
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByTestId('header-profile-menu').click();
        await page.getByTestId('product-logout-button').click();

        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        await expect(supportBanner).toBeVisible();
    });

    test('verweigert einem Unternehmen eine mandantenfremde Stellenbearbeitung', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);

        const response = await page.goto('/employer/jobs/990002/edit');

        expect(response?.status()).toBe(404);
    });
});

test.describe('Englische Oberfläche', () => {
    test.use({ locale: 'en-GB' });

    test('startet trotz englischer Browsersprache auf Deutsch und lässt sich umstellen', async ({
        page,
    }) => {
        await page.goto('/login');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Schön, Sie wiederzusehen',
            }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Welcome back',
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Demo accounts', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByTestId('insert-demo-account-superadmin'),
        ).toHaveText('Insert');
        await expect(page.getByTestId('login-button')).toHaveAccessibleName(
            'Sign in',
        );
        await expectNoSeriousAccessibilityViolations(page);
    });

    test('wechselt die Sprache auf Startseite und Login manuell', async ({
        page,
    }) => {
        await page.goto('/');

        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'The best professionals. Found without borders.',
            }),
        ).toBeVisible();

        await page.goto('/login');
        await page.getByRole('button', { name: 'German', exact: true }).click();

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Schön, Sie wiederzusehen',
            }),
        ).toBeVisible();
        await expect(page.getByTestId('locale-de')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expectNoSeriousAccessibilityViolations(page);
    });

    test('übernimmt nach dem SPA-Login die hinterlegte Kontosprache', async ({
        page,
    }) => {
        await page.goto('/login');
        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();
        await page
            .getByLabel('Email address', { exact: true })
            .fill(accounts.candidate.email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByTestId('login-button').click();

        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        await expect(
            page.getByRole('searchbox', {
                name: 'Plattform durchsuchen',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: 'Passende Jobs',
                exact: true,
            }),
        ).toBeVisible();
    });

    test('zeigt einen fehlgeschlagenen Login nach Sprachwechsel vollständig auf Englisch', async ({
        page,
    }) => {
        await page.goto('/login');
        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();
        await page
            .getByLabel('Email address', { exact: true })
            .fill('unknown@wannemueller.dev');
        await page.getByLabel('Password', { exact: true }).fill('Wrong-2026!');
        await page.getByTestId('login-button').click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(
            page.getByText('These credentials do not match our records.', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByTestId('login-button')).toHaveAccessibleName(
            'Sign in',
        );
    });
});

test.describe('Mobile Abnahme', () => {
    test.use({
        viewport: {
            width: 390,
            height: 844,
        },
        hasTouch: true,
        isMobile: true,
    });

    test('zeigt die Sprachwahl auf der mobilen Startseite direkt an', async ({
        page,
    }) => {
        await page.goto('/');

        await expect(
            page.getByRole('group', { name: 'Sprache', exact: true }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'The best professionals. Found without borders.',
            }),
        ).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('wechselt die Sprache auf dem mobilen Login ohne horizontalen Overflow', async ({
        page,
    }) => {
        await page.goto('/login');

        await expect(
            page.getByRole('group', { name: 'Sprache', exact: true }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Englisch', exact: true })
            .click();

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Welcome back',
            }),
        ).toBeVisible();
        await expect(page.getByTestId('locale-en')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expectNoHorizontalOverflow(page);
        await expectNoSeriousAccessibilityViolations(page);
    });

    test('bleibt im Firmen-Dashboard und in der Stellenliste ohne horizontalen Overflow', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await expectAccessibleAppChrome(page);
        await expectNoHorizontalOverflow(page);
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByRole('button', { name: 'Navigation öffnen' }).click();
        await expect(
            page.getByRole('link', {
                name: 'Stellenanzeigen',
                exact: true,
            }),
        ).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await page.keyboard.press('Escape');
        await page.goto('/employer/jobs');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Stellenanzeigen',
            }),
        ).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('hält Demo-Zugänge und Login bei 320 Pixeln vollständig bedienbar', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 760 });
        await page.goto('/login');

        await expect(page.getByTestId('demo-account-picker')).toBeVisible();
        await expect(
            page.getByTestId('insert-demo-account-candidate10'),
        ).toBeVisible();
        const lastAccount = page.getByTestId('insert-demo-account-candidate10');
        await lastAccount.scrollIntoViewIfNeeded();
        await expect(lastAccount).toBeInViewport();
        await lastAccount.click();
        await expect(
            page.getByLabel('E-Mail-Adresse', { exact: true }),
        ).toHaveValue('candidate10@wannemueller.dev');
        await expect(page.getByLabel('Passwort', { exact: true })).toHaveValue(
            password,
        );

        const submit = page.getByTestId('login-button');
        await submit.scrollIntoViewIfNeeded();
        await expect(submit).toBeInViewport();
        await expectNoHorizontalOverflow(page);
        await expectNoSeriousAccessibilityViolations(page);
        await submit.click();
        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        await expect(page.getByRole('main')).toBeVisible();
    });
});

test.describe('Tablet-Navigation', () => {
    for (const width of [640, 1023]) {
        test(`hält die öffentliche Navigation bei ${width} Pixeln vollständig erreichbar`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/');

            const menuButton = page.getByRole('button', {
                name: 'Menü öffnen',
            });
            await expect(menuButton).toBeVisible();
            await expect(
                page.getByRole('group', { name: 'Sprache', exact: true }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Preise', exact: true }),
            ).toHaveCount(0);

            await menuButton.click();

            await expect(
                page.getByRole('link', {
                    name: 'Für Unternehmen',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', {
                    name: 'Für Fachkräfte',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', {
                    name: 'So funktioniert’s',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Preise', exact: true }),
            ).toBeVisible();
            await expectNoHorizontalOverflow(page);
            await expectNoSeriousAccessibilityViolations(page, 'header');
        });
    }
});
