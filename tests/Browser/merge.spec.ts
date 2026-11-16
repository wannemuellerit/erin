import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('E-Mail-Adresse', { exact: true }).fill(email);
    await page.getByLabel('Passwort', { exact: true }).fill('password');
    await page.getByTestId('login-button').click();
    await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
}

test.describe('Faden merge', () => {
    test('shows real candidate totals, profile cards and an honest empty filter result', async ({
        page,
    }) => {
        await login(page, 'unternehmen.mueller@wannemueller.dev');
        const response = await page.goto('/employer/candidates');
        expect(response?.ok()).toBeTruthy();
        const props = await page.evaluate(
            (html) => {
                const document = new DOMParser().parseFromString(
                    html,
                    'text/html',
                );

                return JSON.parse(
                    document.querySelector('script[data-page="app"]')!
                        .textContent!,
                ).props;
            },
            await response!.text(),
        );
        expect(props.published_count).toBeGreaterThan(0);
        expect(props.candidates.total).toBe(props.published_count);
        await page.goto('/employer/candidates');
        await expect(page.locator('main')).toContainText(
            `${props.published_count} veröffentlichte Profile`,
        );
        await expect(
            page.locator('a[href^="/employer/candidates/"]'),
        ).toHaveCount(props.candidates.data.length);
        await expect(
            page.getByRole('heading', { name: 'Keine Fachkräfte gefunden' }),
        ).toHaveCount(0);
        await page.goto('/employer/candidates?country=ZZ');
        await expect(
            page.getByRole('heading', { name: 'Keine Fachkräfte gefunden' }),
        ).toBeVisible();
        await expect(page.locator('main')).toContainText(
            '0 passende Fachkräfte',
        );
        await expect(page.locator('main')).toContainText(
            `${props.published_count} veröffentlichte Profile`,
        );
    });

    test('uses the Faden brand on login and unknown pages', async ({
        page,
    }) => {
        await page.goto('/login');
        await expect(page).toHaveTitle(/Faden/);
        await expect(page.locator('body')).toContainText('faden');
        await page.goto('/merge-check-unknown-page');
        await expect(page.locator('body')).toContainText('faden');
        await expect(
            page.getByRole('img', { name: 'Faden Logo' }),
        ).toBeVisible();
    });

    test('combines detailed job fields with local templates and translations', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await login(page, 'unternehmen.mueller@wannemueller.dev');
        await page.goto('/employer/jobs/create');
        await expect(page.locator('main')).toBeVisible();
        await expect(page.locator('main')).toContainText('Kurzbeschreibung');
        await expect(page.locator('main')).toContainText('Englisch');
        await expect(page.locator('main')).toContainText('Screening');
        // Render an eligible template without creating or changing server data.
        await page.goto('/employer/jobs');
        await page.route('**/employer/jobs/create', async (route) => {
            const response = await route.fetch();
            const payload = await response.json();
            payload.props.templates = [
                {
                    id: 987654,
                    name: 'Merge-Testvorlage',
                    is_premium: false,
                    content: { title: 'Vorlage aus lokalem Stand' },
                },
            ];
            await route.fulfill({ response, json: payload });
        });
        await page.locator('a[href$="/employer/jobs/create"]').first().click();
        await page
            .getByRole('combobox', { name: 'Vorlage', exact: true })
            .selectOption('987654');
        await page.getByRole('button', { name: 'Vorlage übernehmen' }).click();
        await expect(
            page.getByRole('textbox', {
                name: 'Titel der Stellenanzeige *',
                exact: true,
            }),
        ).toHaveValue('Vorlage aus lokalem Stand');
        expect(errors).toEqual([]);
    });

    test('keeps candidate document management accessible without frontend errors', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await login(page, 'candidate01@wannemueller.dev');
        await page.goto('/candidate/profile');
        await page.getByRole('button', { name: /Dokumente/ }).click();
        await expect(page.locator('main')).toContainText('Dokument');
        await expect(page.locator('input[type="file"]').first()).toBeAttached();
        expect(errors).toEqual([]);
    });
});
