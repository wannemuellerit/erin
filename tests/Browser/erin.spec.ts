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
        name: 'Faden E2E Support',
    },
    companyAdmin: {
        email: 'company.admin.e2e@wannemueller.dev',
        name: 'Faden E2E Firmenadmin',
    },
    recruiter: {
        email: 'recruiter.e2e@wannemueller.dev',
        name: 'Faden E2E Recruiter',
    },
    viewer: {
        email: 'viewer.e2e@wannemueller.dev',
        name: 'Faden E2E Viewer',
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
    const emailInput = page.getByLabel('E-Mail-Adresse', { exact: true });
    const passwordInput = page.getByLabel('Passwort', { exact: true });

    await emailInput.fill(email);
    await passwordInput.fill(password);

    if ((await emailInput.inputValue()) !== email) {
        await emailInput.fill(email);
    }

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

    const search = page.getByRole('combobox', {
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
    await expect(page.getByTestId('sidebar-impersonated-user')).toHaveCount(0);
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

async function useDarkAppearance(page: Page): Promise<void> {
    await page.addInitScript(() => {
        window.localStorage.setItem('appearance', 'dark');
    });
}

async function expectNoColorContrastViolations(page: Page): Promise<void> {
    await expect(
        page.locator('html'),
        `Dark Mode wurde auf ${page.url()} nicht aktiviert.`,
    ).toHaveClass(/\bdark\b/);

    const results = await new AxeBuilder({ page })
        .withRules(['color-contrast'])
        .analyze();
    const violations = results.violations.map((violation) => ({
        id: violation.id,
        help: violation.help,
        nodes: violation.nodes.map((node) => ({
            target: node.target,
            html: node.html,
            failureSummary: node.failureSummary,
        })),
    }));

    expect(
        violations,
        `Axe hat auf ${page.url()} Kontrastverstöße im Dark Mode gefunden:\n${JSON.stringify(violations, null, 2)}`,
    ).toEqual([]);
}

async function expectDarkPagesWithoutContrastViolations(
    page: Page,
    paths: string[],
): Promise<void> {
    const pageErrors: string[] = [];
    const consoleErrors: string[] = [];
    page.on('pageerror', (error) =>
        pageErrors.push(error.stack ?? error.message),
    );
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });

    for (const path of paths) {
        pageErrors.length = 0;
        consoleErrors.length = 0;
        await page.goto(path);
        await page.waitForTimeout(100);
        const viteError = await page
            .locator('vite-error-overlay')
            .evaluateAll((overlays) =>
                overlays
                    .map((overlay) => overlay.shadowRoot?.textContent?.trim())
                    .filter(Boolean)
                    .join('\n'),
            );
        await expect(
            page.locator('body'),
            `Die Seite ${page.url()} wurde nicht vollständig gerendert.${viteError ? `\n${viteError}` : ''}${pageErrors.length ? `\n${pageErrors.join('\n')}` : ''}${consoleErrors.length ? `\n${consoleErrors.join('\n')}` : ''}`,
        ).toContainText(/\S/);
        await expect(page.getByRole('main').first()).toBeVisible();
        await expectNoColorContrastViolations(page);
    }
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
        await expect(page.getByTestId('header-profile-menu')).toContainText(
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

    test('rendert leere Admin-Listen ohne Frontendfehler', async ({ page }) => {
        const pageErrors: Error[] = [];
        page.on('pageerror', (error) => pageErrors.push(error));

        await logIn(page, accounts.admin.email);
        await page.goto('/admin/companies?search=keine-demo-firma');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Unternehmen',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Keine Einträge vorhanden',
            }),
        ).toBeVisible();
        expect(pageErrors).toEqual([]);
    });

    test('öffnet leere Superadmin-Bereiche mit Lucide-Icons fehlerfrei', async ({
        page,
    }) => {
        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await logIn(page, accounts.admin.email);

        for (const route of [
            {
                link: 'Dokumentprüfung',
                path: '/admin/documents',
                heading: 'Dokumentenprüfung',
            },
            {
                link: 'Referrals',
                path: '/admin/referrals',
                heading: 'Referrals',
            },
            {
                link: 'System',
                path: '/admin/system',
                heading: 'System & Governance',
            },
            {
                link: 'Einstellungen',
                path: '/admin/settings',
                heading: 'Plattformeinstellungen',
            },
        ]) {
            await page
                .getByRole('link', { name: route.link, exact: true })
                .click();
            await expect(page).toHaveURL(route.path);
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: route.heading,
                    exact: true,
                }),
            ).toBeVisible();
            await expect(page.getByRole('main')).toBeVisible();
            await expect(page.locator('vite-error-overlay')).toHaveCount(0);
        }

        expect(pageErrors).toEqual([]);
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
        await expect(page.getByTestId('header-profile-menu')).toContainText(
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
        await expect(page.getByTestId('header-profile-menu')).toContainText(
            accounts.company.name,
        );
        await expect(
            page.getByText('Interview geplant', { exact: true }),
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

    test('öffnet die Bewerbungen als Marie Müller fehlerfrei über die Navigation', async ({
        page,
    }) => {
        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await logIn(page, accounts.company.email);

        await page
            .getByRole('link', { name: 'Bewerbungen', exact: true })
            .click();

        await expect(page).toHaveURL(/\/employer\/pipeline$/);
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Recruiting-Pipeline',
            }),
        ).toBeVisible();
        await expect(page.getByRole('main')).toContainText(/Bewerbung/);
        await expect(page.locator('vite-error-overlay')).toHaveCount(0);

        const status = page
            .getByRole('combobox', { name: 'Bewerbungsstatus ändern' })
            .first();
        await status.selectOption('interview_completed');
        await expect(status).toHaveValue('interview_completed');
        await expect(
            page.locator('[data-testid="pipeline-transition-error"]'),
        ).toBeEmpty();
        expect(pageErrors).toEqual([]);
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

    test('plant Interviews und prüft den E2EE-Raumlebenszyklus mit einem Browser-Mock', async ({
        page,
    }) => {
        await page.addInitScript(() => {
            type MockOptions = {
                e2eeKey: string;
                onDisconnected: () => void;
                onChatMessage: (
                    message: string,
                    senderIdentity: string,
                    senderName: string,
                    sentAt?: string,
                    id?: string,
                ) => void;
            };
            type MockWindow = Window & {
                __erinLiveKitCalls: string[];
                __erinReceiveLiveKitChat: (message: string) => void;
                __ERIN_LIVEKIT_ROOM_MOCK__: (options: MockOptions) => {
                    connect: () => Promise<void>;
                    disconnect: () => void;
                    setCameraEnabled: (enabled: boolean) => Promise<void>;
                    setMicrophoneEnabled: (enabled: boolean) => Promise<void>;
                    setScreenShareEnabled: (enabled: boolean) => Promise<void>;
                    switchActiveDevice: () => Promise<void>;
                    getDevices: () => Promise<[never[], never[]]>;
                    sendChatMessage: (message: string) => Promise<void>;
                };
            };
            const mockWindow = window as unknown as MockWindow;
            mockWindow.__erinLiveKitCalls = [];
            mockWindow.__erinReceiveLiveKitChat = () => {};
            mockWindow.__ERIN_LIVEKIT_ROOM_MOCK__ = (options) => {
                mockWindow.__erinReceiveLiveKitChat = (message) =>
                    options.onChatMessage(
                        message,
                        'erin-user-candidate',
                        'E2E Fachkraft',
                        new Date().toISOString(),
                        `remote-${Date.now()}`,
                    );

                return {
                    connect: async () => {
                        mockWindow.__erinLiveKitCalls.push(
                            `connect:e2ee:${options.e2eeKey}`,
                        );
                    },
                    disconnect: () => {
                        mockWindow.__erinLiveKitCalls.push('disconnect');
                        options.onDisconnected();
                    },
                    setCameraEnabled: async (enabled) => {
                        mockWindow.__erinLiveKitCalls.push(`camera:${enabled}`);
                    },
                    setMicrophoneEnabled: async (enabled) => {
                        mockWindow.__erinLiveKitCalls.push(
                            `microphone:${enabled}`,
                        );
                    },
                    setScreenShareEnabled: async (enabled) => {
                        mockWindow.__erinLiveKitCalls.push(`screen:${enabled}`);
                    },
                    switchActiveDevice: async () => {},
                    getDevices: async () => [[], []],
                    sendChatMessage: async (message) => {
                        if (message === 'nicht zustellbar') {
                            throw new Error('simulated data-channel failure');
                        }

                        mockWindow.__erinLiveKitCalls.push(`chat:${message}`);
                    },
                };
            };
        });
        await logIn(page, accounts.company.email);
        await page.route('**/interviews/*/token', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    url: 'wss://livekit.eu.example.test',
                    token: 'browser-mock-token',
                    participantIdentity: 'erin-user-company',
                    participantName: 'E2E Unternehmen',
                }),
            });
        });
        await page.goto('/employer/interviews');

        const join = page.getByTestId('livekit-join').first();
        await expect(join).toBeEnabled();
        await join.click();
        await expect(page.getByRole('alert')).toContainText(
            'Der sichere Videoraum konnte nicht geöffnet werden.',
        );

        await page.unroute('**/interviews/*/token');
        await page.route('**/interviews/*/token', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    url: 'wss://livekit.eu.example.test',
                    token: 'browser-mock-token',
                    e2eeKey: 'browser-mock-e2ee-key',
                    participantIdentity: 'erin-user-company',
                    participantName: 'E2E Unternehmen',
                }),
            });
        });
        await join.click();
        await expect(
            page.getByText('Ende-zu-Ende-Verschlüsselung ist aktiv'),
        ).toBeVisible();
        await expect(page.getByTestId('livekit-chat')).toBeVisible();
        await page.getByTestId('livekit-chat-input').fill('Guten Tag!');
        await page
            .getByRole('button', { name: 'Chatnachricht senden' })
            .click();
        await expect(page.getByTestId('livekit-chat-message')).toContainText(
            'Guten Tag!',
        );
        await page.evaluate(() => {
            (
                window as unknown as Window & {
                    __erinReceiveLiveKitChat: (message: string) => void;
                }
            ).__erinReceiveLiveKitChat('Willkommen zum Interview.');
        });
        await expect(page.getByTestId('livekit-chat-message')).toHaveCount(2);
        await expect(
            page.getByTestId('livekit-chat-message').last(),
        ).toContainText('Willkommen zum Interview.');
        await page.getByTestId('livekit-chat-input').fill('nicht zustellbar');
        await page
            .getByRole('button', { name: 'Chatnachricht senden' })
            .click();
        await expect(page.getByRole('alert')).toContainText(
            'Die Chatnachricht konnte nicht gesendet werden.',
        );
        await expect(page.getByTestId('livekit-chat-input')).toHaveValue(
            'nicht zustellbar',
        );
        await page
            .getByRole('button', { name: 'Kamera ein- oder ausschalten' })
            .click();
        await page
            .getByRole('button', { name: 'Mikrofon ein- oder ausschalten' })
            .click();
        await page
            .getByRole('button', {
                name: 'Bildschirmfreigabe ein- oder ausschalten',
            })
            .click();
        await page.getByRole('button', { name: 'Videoraum verlassen' }).click();
        await expect(join).toBeVisible();
        expect(
            await page.evaluate(
                () =>
                    (
                        window as unknown as Window & {
                            __erinLiveKitCalls: string[];
                        }
                    ).__erinLiveKitCalls,
            ),
        ).toEqual([
            'connect:e2ee:browser-mock-e2ee-key',
            'camera:true',
            'microphone:true',
            'chat:Guten Tag!',
            'camera:false',
            'microphone:false',
            'screen:true',
            'disconnect',
        ]);

        const cardsBefore = await page.getByTestId('interview-card').count();
        const start = new Date(Date.now() + 48 * 60 * 60 * 1000);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        await page
            .getByLabel('Beginn des Terminvorschlags')
            .last()
            .fill(start.toISOString().slice(0, 16));
        await page
            .getByLabel('Ende des Terminvorschlags')
            .last()
            .fill(end.toISOString().slice(0, 16));
        await page
            .getByRole('button', { name: 'Terminvorschläge senden' })
            .click();
        await expect(page.getByTestId('interview-card')).toHaveCount(
            cardsBefore + 1,
        );
    });

    test('zeigt blockierte und nicht unterstützte Browser-Push-Berechtigungen verständlich an', async ({
        page,
    }) => {
        await page.addInitScript(() => {
            Object.defineProperty(window, 'Notification', {
                configurable: true,
                value: class MockNotification {
                    static permission = 'denied';
                },
            });
            Object.defineProperty(window, 'PushManager', {
                configurable: true,
                value: class MockPushManager {},
            });
            Object.defineProperty(navigator, 'serviceWorker', {
                configurable: true,
                value: {
                    register: async () => ({
                        pushManager: { getSubscription: async () => null },
                    }),
                },
            });
        });
        await logIn(page, accounts.company.email);
        await page.goto('/settings/notifications');
        await expect(
            page.getByText('Die Browser-Berechtigung wurde blockiert.'),
        ).toBeVisible();

        const worker = await page.request.get('/sw.js');
        expect(await worker.text()).toContain(
            'parsedUrl.origin === self.location.origin',
        );
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

    test('zeigt dem Owner Recruiting-, Team- und Billing-Aktionen', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);

        await page.goto('/employer/jobs');
        await expect(
            page.getByRole('link', { name: 'Neue Stellenanzeige' }),
        ).toBeVisible();
        await page.goto('/employer/team');
        await expect(
            page.getByRole('button', { name: 'Mitglied einladen' }),
        ).toBeVisible();
        await page.goto('/employer/billing');
        await expect(
            page
                .getByRole('button', { name: /Paket (wählen|wechseln)/ })
                .first(),
        ).toBeVisible();
        const invoices = page.getByTestId('billing-invoices');
        await expect(invoices.getByText('ERIN-E2E-2026-001')).toBeVisible();
        await expect(invoices.getByText('Rabattcode ERIN10')).toBeVisible();
        await expect(invoices.getByText('EU_VAT · DE123456789')).toBeVisible();
        await expect(invoices.getByText(/17,10\s*€/)).toBeVisible();
    });

    test('begrenzt Firmenadmins auf Management ohne Billing und Owner-Transfer', async ({
        page,
    }) => {
        await logIn(page, accounts.companyAdmin.email);

        await page.goto('/employer/jobs');
        await expect(
            page.getByRole('link', { name: 'Neue Stellenanzeige' }),
        ).toBeVisible();
        await page.goto('/employer/team');
        await expect(
            page.getByRole('button', { name: 'Mitglied einladen' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Inhaberschaft übertragen' }),
        ).toHaveCount(0);
        await page.goto('/employer/billing');
        await expect(
            page.getByRole('button', { name: /Paket (wählen|wechseln)/ }),
        ).toHaveCount(0);
    });

    test('gibt Recruitern Recruiting-Aktionen, aber keine Firmen- oder Teamverwaltung', async ({
        page,
    }) => {
        await logIn(page, accounts.recruiter.email);

        await page.goto('/employer/jobs');
        await expect(
            page.getByRole('link', { name: 'Neue Stellenanzeige' }),
        ).toBeVisible();
        await page.goto('/employer/company');
        await expect(
            page.getByRole('button', { name: 'Änderungen speichern' }),
        ).toHaveCount(0);
        await page.goto('/employer/team');
        await expect(
            page.getByRole('button', { name: 'Mitglied einladen' }),
        ).toHaveCount(0);
    });

    test('zeigt Viewern nur Leseflächen und blockiert direkte Mutationsseiten', async ({
        page,
    }) => {
        await logIn(page, accounts.viewer.email);

        await page.goto('/employer/jobs');
        await expect(
            page.getByRole('link', { name: 'Neue Stellenanzeige' }),
        ).toHaveCount(0);
        await page.goto('/employer/candidates');
        await expect(
            page.getByText('Gespeicherte Suchen', { exact: true }),
        ).toHaveCount(0);
        await expect(page.locator('input[type="checkbox"]')).toHaveCount(0);

        const response = await page.goto('/employer/jobs/create');
        expect(response?.status()).toBe(403);
    });

    test('wendet Admin-Zuweisungen in einer bestehenden Browser-Sitzung sofort auf Navigation und Aktionen an', async ({
        browser,
        page,
    }) => {
        const viewerMember = () =>
            page.locator('[data-member-email="viewer.e2e@wannemueller.dev"]');
        const viewerContext = await browser.newContext({
            locale: 'de-DE',
            timezoneId: 'Europe/Berlin',
        });
        const viewerPage = await viewerContext.newPage();

        await logIn(viewerPage, accounts.viewer.email);
        await logIn(page, accounts.company.email);
        await page.goto('/employer/team');
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'PATCH' &&
                    response.url().includes('/employer/team/members/'),
            ),
            viewerMember()
                .getByRole('combobox', { name: 'Rolle ändern' })
                .selectOption('admin'),
        ]);

        try {
            await viewerPage.goto('/employer/jobs');
            await expect(
                viewerPage.getByRole('link', { name: 'Neue Stellenanzeige' }),
            ).toBeVisible();
            await viewerPage.goto('/employer/team');
            await expect(
                viewerPage.getByRole('button', { name: 'Mitglied einladen' }),
            ).toBeVisible();
        } finally {
            await page.goto('/employer/team');
            await Promise.all([
                page.waitForResponse(
                    (response) =>
                        response.request().method() === 'PATCH' &&
                        response.url().includes('/employer/team/members/'),
                ),
                viewerMember()
                    .getByRole('combobox', { name: 'Rolle ändern' })
                    .selectOption('viewer'),
            ]);
            await viewerContext.close();
        }
    });

    test('trennt den operativen Visa-Fall für Firma, Fachkraft, Support und Superadmin', async ({
        browser,
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await page.goto('/employer/visa');
        const expandedCompanyCase = page
            .locator('[data-test^="employer-visa-case-"]', {
                hasText: accounts.candidate.name,
            })
            .filter({
                has: page.getByText('E2E Visumunterlagen prüfen'),
            });
        const companyCaseTestId =
            await expandedCompanyCase.getAttribute('data-test');
        expect(companyCaseTestId).not.toBeNull();
        const companyCase = page.getByTestId(companyCaseTestId!);
        await expect(companyCase).toBeVisible();
        const companyToggle = companyCase.getByRole('button', {
            name: /Visa-Fall für Anna Kowalska/,
        });
        await expect(companyToggle).toHaveAttribute('aria-expanded', 'true');
        await companyToggle.click();
        await expect(companyToggle).toHaveAttribute('aria-expanded', 'false');
        await companyToggle.click();
        await expect(companyToggle).toHaveAttribute('aria-expanded', 'true');
        await expect(
            companyCase.getByText('E2E Visumunterlagen prüfen'),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);

        const candidateContext = await browser.newContext({ locale: 'de-DE' });
        const candidatePage = await candidateContext.newPage();
        const supportContext = await browser.newContext({ locale: 'de-DE' });
        const supportPage = await supportContext.newPage();
        const adminContext = await browser.newContext({ locale: 'de-DE' });
        const adminPage = await adminContext.newPage();

        try {
            await logIn(candidatePage, accounts.candidate.email);
            await candidatePage.goto('/candidate/applications');
            const candidateCase = candidatePage.locator(
                '[data-test^="candidate-visa-case-"]',
                { hasText: 'E2E Visumunterlagen prüfen' },
            );
            await expect(candidateCase).toBeVisible();
            await expect(
                candidateCase.getByText('E2E Visumunterlagen prüfen'),
            ).toBeVisible();

            await logIn(supportPage, accounts.support.email);
            await supportPage.goto('/admin/visa');
            const supportCase = supportPage.locator(
                '[data-test^="admin-visa-case-"]',
                { hasText: accounts.candidate.name },
            );
            await expect(supportCase).toBeVisible();
            await expect(
                supportCase.locator('[data-test^="admin-visa-block-"]'),
            ).toHaveCount(0);

            await logIn(adminPage, accounts.admin.email);
            await adminPage.goto('/admin/visa');
            const adminCase = adminPage.locator(
                '[data-test^="admin-visa-case-"]',
                { hasText: accounts.candidate.name },
            );
            await expect(adminCase).toBeVisible();
            await expect(
                adminCase.locator('[data-test^="admin-visa-block-"]'),
            ).toBeVisible();
            await adminCase
                .locator('[data-test^="admin-visa-details-"]')
                .click();
            await expect(
                adminPage.getByText('E2E Visumunterlagen prüfen'),
            ).toBeVisible();
        } finally {
            await candidateContext.close();
            await supportContext.close();
            await adminContext.close();
        }
    });
});

test.describe('Onboarding und Abrechnung', () => {
    test('führt eine Fachkraft vom Onboarding in ihr Profil', async ({
        page,
    }) => {
        await submitLogin(page, accounts.onboardingCandidate.email);
        await expect(page).toHaveURL(/\/onboarding$/);
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByLabel('Vorname').fill('E2E');
        await page.getByLabel('Nachname').fill('Kandidat');
        await page.getByLabel('Aktuelles Land (ISO)').fill('PL');
        await page.getByLabel('Aktuelle Stadt').fill('Wrocław');
        await page.getByLabel('Telefonnummer').fill('+49 170 1234567');
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();

        await page.getByLabel('Berufsfeld').selectOption({ index: 1 });
        await page.getByLabel('Wunschposition').fill('Elektriker');
        await page.getByLabel('Jahre Berufserfahrung').fill('5');
        await page
            .getByLabel('Kurzprofil')
            .fill(
                'Ich bin ausgebildeter Elektriker mit fünf Jahren Berufserfahrung und möchte langfristig in einem deutschen Industriebetrieb arbeiten.',
            );
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();

        await page.getByPlaceholder('Arbeitgeber *').fill('E2E Elektrotechnik');
        await page.getByPlaceholder('Position *').fill('Elektriker');
        await page.locator('input[type="date"]').first().fill('2020-01-01');
        await page
            .getByRole('button', { name: 'Ausbildung hinzufügen' })
            .click();
        await page.getByPlaceholder('Institution *').fill('E2E Berufsschule');
        await page
            .getByPlaceholder('Abschluss *')
            .fill('Elektroniker für Betriebstechnik');
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();

        await page
            .getByRole('button', { name: /Industrie|Elektr/i })
            .first()
            .click();
        await page.locator('input[type="checkbox"]').first().check();
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();

        await page
            .getByText(
                'Ich habe verstanden, dass Dokumente separat freigegeben werden und nicht öffentlich abrufbar sind.',
            )
            .click();
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();
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

        await page.getByText('Basic', { exact: true }).click();
        await page
            .getByRole('button', { name: 'Speichern und weiter' })
            .click();
        await page
            .getByLabel('Rechtlicher Firmenname')
            .fill('E2E Onboarding GmbH');
        await page
            .getByLabel('Rechnungs-E-Mail')
            .fill('rechnung.e2e@wannemueller.dev');
        await page.getByLabel('Branche').fill('Elektrotechnik');
        await page.getByLabel('Mitarbeitende').fill('25');
        await page.getByLabel('Straße und Hausnummer').fill('Teststraße 42');
        await page.getByLabel('Postleitzahl').fill('40210');
        await page.getByLabel('Stadt').fill('Düsseldorf');
        await page.getByLabel('Land (ISO)').fill('DE');
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

test.describe('Vollständige zusätzliche Locale-Onboardings', () => {
    for (const locale of ['pl', 'ro', 'hr', 'es', 'pt'] as const) {
        test(`führt den Kandidatenflow vollständig auf ${locale} aus`, async ({
            page,
        }, testInfo) => {
            const missingTranslations: string[] = [];
            page.on('console', (message) => {
                if (
                    message.type() === 'warning' &&
                    /Not found|Fall back to translate/i.test(message.text())
                ) {
                    missingTranslations.push(message.text());
                }
            });

            await submitLogin(
                page,
                `onboarding.${locale}.candidate@wannemueller.dev`,
            );
            await expect(page).toHaveURL(/\/onboarding$/);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);

            const contact = page.locator(
                '[data-test="candidate-onboarding-step-2"]',
            );
            const contactInputs = contact.locator('input');
            await contactInputs.nth(0).fill('E2E');
            await contactInputs.nth(1).fill(locale.toUpperCase());
            await contactInputs.nth(2).fill('PL');
            await contactInputs.nth(3).fill('Wrocław');
            await contactInputs.nth(4).fill('+49 170 1234567');
            await contact.locator('button[type="submit"]').click();

            const profession = page.locator(
                '[data-test="candidate-onboarding-step-3"]',
            );
            await profession
                .locator('select')
                .first()
                .selectOption({ index: 1 });
            await profession.locator('input').nth(0).fill('Electrician');
            await profession.locator('input').nth(1).fill('Electrician');
            await profession.locator('input[type="number"]').fill('5');
            await profession
                .locator('textarea')
                .fill(
                    'Experienced industrial electrician with five years of professional experience seeking long-term employment in Germany.',
                );
            await profession.locator('button[type="submit"]').click();

            const history = page.locator(
                '[data-test="candidate-onboarding-step-4"]',
            );
            const historyInputs = history.locator('input');
            await historyInputs.nth(0).fill('E2E Electrics');
            await historyInputs.nth(1).fill('Electrician');
            await historyInputs.nth(2).fill('PL');
            await historyInputs.nth(3).fill('2020-01-01');
            await history.locator('button[type="submit"]').click();

            const skills = page.locator(
                '[data-test="candidate-onboarding-step-5"]',
            );
            await skills.locator('button[type="button"]').first().click();
            await skills.locator('input[type="checkbox"]').first().check();
            await skills.locator('button[type="submit"]').click();

            const uploads = page.locator(
                '[data-test="candidate-onboarding-step-6"]',
            );
            await uploads.locator('input[type="checkbox"]').check();
            await uploads.locator('button[type="submit"]').click();

            const finish = page.locator(
                '[data-test="candidate-onboarding-step-7"]',
            );
            await finish.locator('button[type="submit"]').click();
            await expect(page).toHaveURL(/\/candidate\/profile$/);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expectNoHorizontalOverflow(page);
            await expectNoSeriousAccessibilityViolations(page);
            expect(missingTranslations).toEqual([]);
            await page.screenshot({
                path: testInfo.outputPath(`candidate-onboarding-${locale}.png`),
                fullPage: true,
            });
        });
    }
});

test.describe('Suche und Echtzeit', () => {
    test('blendet den zurückgestellten Produktivitätsbereich aus und blockiert Direktaufrufe', async ({
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await expect(
            page.getByRole('link', { name: 'Recruiting-Produktivität' }),
        ).toHaveCount(0);

        const response = await page.goto('/employer/productivity');

        expect(response?.status()).toBe(404);
        await expect(
            page.getByRole('heading', { name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });

    test('öffnet rollenabhängige Suchtreffer per Tastatur', async ({
        browser,
        page,
    }) => {
        await logIn(page, accounts.company.email);
        await page.keyboard.press('Control+K');
        const companySearch = page.getByTestId('global-search-input');
        await expect(companySearch).toBeFocused();
        await companySearch.fill('E2E Suchstelle');
        await expect(
            page.getByTestId('global-search-result-job').first(),
        ).toContainText('E2E Suchstelle Elektrotechnik');
        await companySearch.press('Enter');
        await expect(page).toHaveURL(/\/employer\/jobs\/990003\/edit$/);

        const candidateContext = await browser.newContext({ locale: 'de-DE' });
        const candidatePage = await candidateContext.newPage();

        try {
            await logIn(candidatePage, accounts.candidate.email);
            await candidatePage.keyboard.press('Control+K');
            const candidateSearch = candidatePage.getByTestId(
                'global-search-input',
            );
            await candidateSearch.fill('E2E Suchstelle');
            await expect(
                candidatePage.getByTestId('global-search-result-job').first(),
            ).toContainText('E2E Suchstelle Elektrotechnik');
            await candidateSearch.press('Enter');
            await expect(candidatePage).toHaveURL(/\/candidate\/jobs\/990003$/);
        } finally {
            await candidateContext.close();
        }
    });

    test.skip('hängt Firmenaktivität in einem zweiten Browser live und dedupliziert an', async ({
        browser,
        page,
    }) => {
        const recruiterContext = await browser.newContext({ locale: 'de-DE' });
        const recruiterPage = await recruiterContext.newPage();
        const realtimeFrames: string[] = [];
        recruiterPage.on('websocket', (socket) => {
            socket.on('framereceived', (event) =>
                realtimeFrames.push(String(event.payload)),
            );
        });

        try {
            await logIn(page, accounts.company.email);
            await page.goto('/employer/productivity');

            await logIn(recruiterPage, accounts.recruiter.email);
            const channelAuthorized = recruiterPage.waitForResponse(
                (response) =>
                    response.url().includes('/broadcasting/auth') &&
                    (response.request().postData() ?? '').includes(
                        'private-company.',
                    ),
            );
            await recruiterPage.goto('/employer/productivity');
            await channelAuthorized;

            const title = `E2E Live-Aktivität ${Date.now()}`;
            const dueAt = new Date(Date.now() + 72 * 60 * 60 * 1000)
                .toISOString()
                .slice(0, 16);
            await page.getByTestId('reminder-title').fill(title);
            await page.getByTestId('reminder-due').fill(dueAt);
            await page.getByTestId('reminder-submit').click();

            const liveActivity = recruiterPage.locator(
                '[data-test^="activity-link-"]',
                { hasText: title },
            );
            await expect(liveActivity).toHaveCount(1);
            await expect
                .poll(
                    () =>
                        realtimeFrames.filter((frame) =>
                            frame.includes('activity.created'),
                        ).length,
                )
                .toBeGreaterThan(0);

            await recruiterPage.reload();
            await expect(
                recruiterPage.locator('[data-test^="activity-link-"]', {
                    hasText: title,
                }),
            ).toHaveCount(1);

            const reminder = page.locator('[data-test^="reminder-"]', {
                hasText: title,
            });
            const reminderId = (
                await reminder.getAttribute('data-test')
            )?.replace('reminder-', '');
            expect(reminderId).toBeTruthy();
            await reminder.getByTestId(`reminder-delete-${reminderId}`).click();
        } finally {
            await recruiterContext.close();
        }
    });

    test('überträgt Nachrichten zwischen zwei Browsern live und bedient Voice mobil', async ({
        browser,
        page,
    }) => {
        test.setTimeout(45_000);
        const browserErrors: string[] = [];
        page.on('pageerror', (error) => browserErrors.push(error.message));
        const candidateContext = await browser.newContext({
            locale: 'de-DE',
            viewport: { width: 1440, height: 1000 },
        });
        await candidateContext.addInitScript(() => {
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: {
                    getUserMedia: async () => ({
                        getTracks: () => [{ stop: () => undefined }],
                    }),
                },
            });

            class BrowserMediaRecorder extends EventTarget {
                mimeType = 'audio/webm';

                start(): void {}

                stop(): void {
                    this.dispatchEvent(
                        new MessageEvent('dataavailable', {
                            data: new Blob(['e2e voice'], {
                                type: this.mimeType,
                            }),
                        }),
                    );
                    this.dispatchEvent(new Event('stop'));
                }
            }

            Object.defineProperty(window, 'MediaRecorder', {
                configurable: true,
                value: BrowserMediaRecorder,
            });
        });
        const candidatePage = await candidateContext.newPage();
        const candidateRealtimeFrames: string[] = [];
        candidatePage.on('websocket', (socket) => {
            socket.on('framereceived', (event) =>
                candidateRealtimeFrames.push(String(event.payload)),
            );
        });
        candidatePage.on('pageerror', (error) =>
            browserErrors.push(error.message),
        );

        try {
            await logIn(page, accounts.company.email);
            const companyConversationAuthorized = page.waitForResponse(
                (response) =>
                    response.url().includes('/broadcasting/auth') &&
                    (response.request().postData() ?? '').includes(
                        'private-conversation.',
                    ),
            );
            await page.goto('/employer/messages');
            await companyConversationAuthorized;
            await logIn(candidatePage, accounts.candidate.email);
            const candidateConversationAuthorized =
                candidatePage.waitForResponse(
                    (response) =>
                        response.url().includes('/broadcasting/auth') &&
                        (response.request().postData() ?? '').includes(
                            'private-conversation.',
                        ),
                );
            await candidatePage.goto('/candidate/messages');
            await candidateConversationAuthorized;

            expect(browserErrors).toEqual([]);

            await expect(
                page.locator('[data-test^="conversation-"]').first(),
            ).toBeVisible();
            await expect(
                candidatePage.locator('[data-test^="conversation-"]').first(),
            ).toBeVisible();

            const text = `E2E Live-Nachricht ${Date.now()}`;
            await page.getByTestId('message-compose').fill(text);
            await page.getByTestId('message-send').click();
            await expect(
                page.locator('[data-test^="message-"]', { hasText: text }),
            ).toHaveCount(1);
            await expect
                .poll(() => candidateRealtimeFrames, { timeout: 10_000 })
                .toContainEqual(expect.stringContaining('message.sent'));
            await expect(
                candidatePage.locator('[data-test^="message-"]', {
                    hasText: text,
                }),
            ).toBeVisible({ timeout: 10_000 });

            await candidatePage.setViewportSize({ width: 390, height: 844 });
            await candidatePage.getByTestId('voice-record-toggle').click();
            await expect(
                candidatePage.getByRole('button', {
                    name: 'Aufnahme beenden',
                }),
            ).toBeVisible();
            await candidatePage.getByTestId('voice-record-toggle').click();
            await expect(
                candidatePage.getByLabel('Vorschau der Sprachnachricht'),
            ).toBeVisible();
            await candidatePage
                .getByRole('button', { name: 'Sprachnachricht verwerfen' })
                .click();
            await expect(
                candidatePage.getByLabel('Vorschau der Sprachnachricht'),
            ).toHaveCount(0);
            await expectNoHorizontalOverflow(candidatePage);
            await expectNoSeriousAccessibilityViolations(candidatePage);
        } finally {
            await candidateContext.close();
        }
    });

    test('nimmt Unternehmensanalytics mobil, per Tastatur und barrierearm ab', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await logIn(page, accounts.company.email);
        await page.goto('/employer/analytics');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Analytics' }),
        ).toBeVisible();
        const apply = page.getByRole('button', { name: 'Anwenden' });
        await apply.focus();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'GET' &&
                    response.url().includes('/employer/analytics?'),
            ),
            page.keyboard.press('Enter'),
        ]);
        await expect(
            page.getByRole('link', { name: 'CSV exportieren' }),
        ).toHaveAttribute('href', /\/employer\/analytics\/export/);
        await expectNoHorizontalOverflow(page);
        await expectNoSeriousAccessibilityViolations(page);
    });
});

test.describe('Support und Mandantentrennung', () => {
    test('führt den geprüften Support-Chatbot mit Quelle, Feedback und idempotenter Zammad-Übergabe', async ({
        page,
    }) => {
        let handoffRequests = 0;
        const assistantMessage = {
            id: 501,
            author: 'assistant',
            body: 'Du findest die Rechnungen im Firmenbereich unter Abrechnung.',
            sources: [
                {
                    id: 91,
                    title: 'Abrechnung in Faden',
                    url: '/employer/billing',
                    version: 3,
                },
            ],
            escalation_required: false,
            feedback: null,
            created_at: new Date().toISOString(),
        };
        await page.route('**/support/chat/sessions', async (route) => {
            await route.fulfill({
                status: 201,
                contentType: 'application/json',
                body: JSON.stringify({
                    session: {
                        id: 'e2e-support-chat',
                        locale: 'de',
                        status: 'active',
                        ticket_id: null,
                        retention_expires_at: new Date(
                            Date.now() + 7 * 86_400_000,
                        ).toISOString(),
                    },
                }),
            });
        });
        await page.route(
            '**/support/chat/sessions/e2e-support-chat/messages',
            async (route) => {
                await route.fulfill({
                    status: 201,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: assistantMessage }),
                });
            },
        );
        await page.route(
            '**/support/chat/sessions/e2e-support-chat/messages/501/feedback',
            async (route) => {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        message: {
                            ...assistantMessage,
                            feedback: 'helpful',
                        },
                    }),
                });
            },
        );
        await page.route(
            '**/support/chat/sessions/e2e-support-chat/handoff',
            async (route) => {
                handoffRequests += 1;
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ url: '/support' }),
                });
            },
        );

        await logIn(page, accounts.candidate.email);
        await page.goto('/support');
        await expect(
            page.getByRole('heading', { name: 'Faden Support-Assistent' }),
        ).toBeVisible();
        await page
            .getByLabel('Frage an den Support-Assistenten')
            .fill('Wo finde ich meine Rechnungen?');
        await page
            .getByLabel('Frage an den Support-Assistenten')
            .press('Control+Enter');
        await expect(page.getByText(assistantMessage.body)).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: 'Abrechnung in Faden (Version 3)',
            }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Antwort hilfreich' }).click();
        await expect(
            page.getByRole('button', { name: 'Antwort hilfreich' }),
        ).toHaveClass(/bg-green-100/);
        await page.getByRole('checkbox').check();
        await expectNoSeriousAccessibilityViolations(page);
        await page
            .getByRole('button', { name: 'Support-Mitarbeiter kontaktieren' })
            .click();
        await expect.poll(() => handoffRequests).toBe(1);
    });

    test('zeigt Superadmins die Trust- und Moderationszentrale', async ({
        page,
    }) => {
        await logIn(page, accounts.admin.email);
        await page.goto('/admin/support');

        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Trust- und Moderationszentrale',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                level: 3,
                name: 'Ausstehendes Feedback',
            }),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);
    });

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
        await expect(
            page.getByText('Eingeloggt als', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByTestId('sidebar-impersonated-user'),
        ).toBeVisible();
        await expectNoSeriousAccessibilityViolations(page);

        await page.getByTestId('header-profile-menu').click();
        await page.getByTestId('product-logout-button').click();

        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        await expect(supportBanner).toBeVisible();
    });

    test('zeigt eine lokal ausstehende Antwort bei Ausfall an und sendet sie nach Wiederherstellung erneut', async ({
        page,
    }) => {
        const reply = `Browsertest: Antwort nach simuliertem Zammad-Ausfall ${Date.now()}`;

        await logIn(page, accounts.candidate.email);
        await page.goto('/support');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Support' }),
        ).toBeVisible();

        const inertiaPage = (await page.evaluate(
            () => window.history.state?.page,
        )) as {
            props: Record<string, unknown> & {
                errors?: Record<string, string>;
            };
        };
        inertiaPage.props.errors = {
            message:
                'Die Zammad-Verbindung ist vorübergehend nicht erreichbar.',
        };

        await page.route('**/support/tickets/*/reply', async (route) => {
            await route.fulfill({
                status: 422,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Inertia': 'true',
                },
                body: JSON.stringify(inertiaPage),
            });
        });

        await page.getByLabel('Nachricht', { exact: true }).fill(reply);
        await page.getByRole('button', { name: 'Senden', exact: true }).click();

        const failedMessage = page.locator('[data-test^="support-message-"]', {
            hasText: reply,
        });
        await expect(failedMessage).toContainText('Fehlgeschlagen');
        await expect(
            failedMessage.getByRole('button', { name: 'Erneut senden' }),
        ).toBeVisible();

        await page.unroute('**/support/tickets/*/reply');
        await failedMessage
            .getByRole('button', { name: 'Erneut senden' })
            .click();

        await expect(failedMessage).not.toContainText('Fehlgeschlagen');
        await expect(
            page.getByRole('button', { name: 'Erneut senden' }),
        ).toHaveCount(0);
    });

    test('verweigert einem Unternehmen eine mandantenfremde Stellenbearbeitung', async ({
        page,
    }) => {
        await logIn(page, accounts.recruiter.email);

        const response = await page.goto('/employer/jobs/990002/edit');

        expect(response?.status()).toBe(404);
    });
});

test.describe('Dark-Mode-Kontraste', () => {
    test.beforeEach(async ({ page }) => {
        await useDarkAppearance(page);
    });

    test('hält die öffentlichen Seiten und Anmeldung nach WCAG AA lesbar', async ({
        page,
    }) => {
        await expectDarkPagesWithoutContrastViolations(page, [
            '/',
            '/pricing',
            '/contact',
            '/datenschutz',
            '/impressum',
            '/agb',
            '/login',
            '/register',
        ]);
    });

    test('hält den Fachkraft-Bereich nach WCAG AA lesbar', async ({ page }) => {
        await logIn(page, accounts.candidate.email);
        await expectDarkPagesWithoutContrastViolations(page, [
            '/dashboard',
            '/candidate/jobs',
            '/candidate/companies',
            '/candidate/applications',
            '/candidate/profile',
            '/candidate/messages',
            '/candidate/interviews',
            '/candidate/ai-studio',
            '/candidate/referrals',
            '/support',
            '/settings/profile',
            '/settings/security',
            '/settings/notifications',
            '/settings/appearance',
        ]);
    });

    test('hält den Unternehmensbereich nach WCAG AA lesbar', async ({
        page,
    }) => {
        await logIn(page, accounts.companyAdmin.email);
        await expectDarkPagesWithoutContrastViolations(page, [
            '/dashboard',
            '/employer/candidates',
            '/employer/jobs',
            '/employer/pipeline',
            '/employer/analytics',
            '/employer/messages',
            '/employer/interviews',
            '/employer/company',
            '/employer/team',
            '/employer/billing',
            '/employer/visa',
            '/support',
        ]);
    });

    test('hält Administration und Support nach WCAG AA lesbar', async ({
        page,
    }) => {
        await logIn(page, accounts.admin.email);
        await expectDarkPagesWithoutContrastViolations(page, [
            '/admin',
            '/admin/users',
            '/admin/companies',
            '/admin/support',
            '/admin/audit',
            '/admin/system',
            '/admin/settings',
        ]);
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
            .getByRole('button', { name: 'English', exact: true })
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
            .getByRole('button', { name: 'English', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'The best professionals. Found without borders.',
            }),
        ).toBeVisible();

        await page.goto('/login');
        await page
            .getByRole('button', { name: 'Deutsch', exact: true })
            .click();

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
            .getByRole('button', { name: 'English', exact: true })
            .click();
        await page
            .getByLabel('Email address', { exact: true })
            .fill(accounts.candidate.email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByTestId('login-button').click();

        await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
        await expect(
            page.getByRole('combobox', {
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
            .getByRole('button', { name: 'English', exact: true })
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
            .getByRole('button', { name: 'English', exact: true })
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
            .getByRole('button', { name: 'English', exact: true })
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
        await logIn(page, accounts.recruiter.email);
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
