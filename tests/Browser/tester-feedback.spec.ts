import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function login(page: Page, email: string) {
    await page.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    await page.goto('/login');
    await page.getByLabel('E-Mail-Adresse', { exact: true }).fill(email);
    await page.getByLabel('Passwort', { exact: true }).fill('password');
    await page.getByTestId('login-button').click();
    await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/);
}

async function contrast(page: Page) {
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    const results = await new AxeBuilder({ page })
        .withRules(['color-contrast'])
        .analyze();
    expect(results.violations).toEqual([]);
}

test('tester feedback: all profile tabs remain readable and clickable in dark mode', async ({
    page,
}) => {
    test.setTimeout(120000);
    await login(page, 'candidate01@wannemueller.dev');
    await page.goto('/candidate/profile');

    for (const tab of [
        'personal',
        'profession',
        'history',
        'skills',
        'availability',
        'documents',
    ]) {
        const button = page.getByTestId(`profile-tab-${tab}`);
        await button.click();
        await expect(button).toHaveCSS('cursor', 'pointer');
        await contrast(page);
    }

    await expect(page.locator('input[type="file"]').first()).toBeAttached();
    await page.screenshot({
        path: 'storage/framework/testing/tester-profile-dark.png',
        fullPage: true,
    });
});

test('tester feedback: job editor and new company location are readable in dark mode', async ({
    page,
}) => {
    test.setTimeout(120000);
    await login(page, 'unternehmen.mueller@wannemueller.dev');
    await page.goto('/employer/jobs/create');
    await contrast(page);
    await page.getByRole('button', { name: /Benachrichtigungen/ }).click();
    await contrast(page);
    await page.keyboard.press('Escape');
    await page.goto('/employer/company');
    await page.getByRole('button', { name: 'Standort hinzufügen' }).click();
    await contrast(page);
    await page.screenshot({
        path: 'storage/framework/testing/tester-company-dark.png',
        fullPage: true,
    });
});

test('tester feedback: shared success and validation messages are visible without exposing HTML', async ({
    page,
}) => {
    await login(page, 'unternehmen.mueller@wannemueller.dev');
    await page.route('**/employer/company', async (route) => {
        const response = await route.fetch();
        const payload = await response.json();
        payload.props.flash = {
            success: 'Das Firmenprofil wurde gespeichert.',
        };
        payload.flash = {
            toast: { type: 'warning', message: 'Native Rückmeldung' },
        };
        payload.props.errors = { name: '<b>Bitte Namen prüfen.</b>' };
        await route.fulfill({ response, json: payload });
    });
    await page.locator('a[href="/employer/company"]').first().click();
    const feedback = page.getByTestId('request-feedback');
    await expect(feedback.getByRole('status')).toHaveText(
        'Das Firmenprofil wurde gespeichert.',
    );
    await expect(
        feedback.getByRole('alert').filter({ hasText: 'Bitte Namen' }),
    ).toContainText('<b>Bitte Namen prüfen.</b>');
    await expect(feedback.locator('b')).toHaveCount(0);
    await expect(
        feedback.getByRole('alert').filter({ hasText: 'Native Rückmeldung' }),
    ).toBeVisible();
    await contrast(page);
    await page.locator('a[href="/employer/jobs"]').first().click();
    await expect(feedback).toHaveCount(0);
});
