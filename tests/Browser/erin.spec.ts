import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const password = 'password';

const accounts = {
    admin: {
        email: 'admin@wannemueller.dev',
        name: 'Wannemüller Admin',
    },
    candidate: {
        email: 'candidate@wannemueller.dev',
        name: 'Anna Kowalska',
    },
    company: {
        email: 'recruiting@mueller-elektro.example',
        name: 'Marie Müller',
    },
} as const;

async function logIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('E-Mail-Adresse', { exact: true }).fill(email);
    await page.getByLabel('Passwort', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();

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
    test('zeigt den Demo-Zugang und setzt die Zugangsdaten barrierearm ein', async ({
        page,
    }) => {
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
        const insert = page.getByTestId('insert-demo-credentials');
        const submit = page.getByTestId('login-button');

        await expect(
            page.getByText('Demo-Zugang', { exact: true }),
        ).toBeVisible();
        await expect(page.getByTestId('demo-email')).toHaveText(
            accounts.admin.email,
        );
        await expect(page.getByTestId('demo-password')).toHaveText(password);
        await expect(email).toHaveAccessibleName('E-Mail-Adresse');
        await expect(loginPassword).toHaveAccessibleName('Passwort');
        await expect(insert).toHaveAccessibleName('Einsetzen');
        await expect(submit).toHaveAccessibleName('Anmelden');

        await insert.click();

        await expect(email).toHaveValue(accounts.admin.email);
        await expect(loginPassword).toHaveValue(password);
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

        await page.getByRole('link', { name: 'Benutzer', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/users$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Benutzerverwaltung',
            }),
        ).toBeVisible();
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
});

test.describe('Englische Oberfläche', () => {
    test.use({ locale: 'en-GB' });

    test('übersetzt den Gast-Login anhand der Browsersprache', async ({
        page,
    }) => {
        await page.goto('/login');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Welcome back',
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Demo access', { exact: true }),
        ).toBeVisible();
        await expect(page.getByTestId('insert-demo-credentials')).toHaveText(
            'Insert',
        );
        await expect(page.getByTestId('login-button')).toHaveAccessibleName(
            'Sign in',
        );
    });

    test('übernimmt nach dem SPA-Login die hinterlegte Kontosprache', async ({
        page,
    }) => {
        await page.goto('/login');
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

    test('bleibt im Firmen-Dashboard und in der Stellenliste ohne horizontalen Overflow', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await expectAccessibleAppChrome(page);
        await expectNoHorizontalOverflow(page);

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
});
