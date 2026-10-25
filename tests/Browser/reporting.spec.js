import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixtures = JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(email.startsWith('system@') ? /\/system$/ : /\/dashboard$/);
}

test('platform intelligence uses real charts, responsive filters, themes and accessible exports', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await login(page, 'system@browser.example.test');
    await page.getByRole('navigation', { name: 'Workspace navigation' }).getByRole('link', { name: 'Analytics & impact' }).click();
    await expect(page.getByRole('heading', { name: 'Platform overview', exact: true })).toBeVisible();
    const growth = page.locator('[data-chart]').filter({ has: page.getByRole('heading', { name: 'Institutions created over time', exact: true }) });
    await expect(growth).toHaveAttribute('data-chart-ready', 'true');
    await growth.locator('canvas').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-analytics-status]')).toContainText('Institutions created over time:');
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    await expect(page.locator('.workspace')).toHaveAttribute('data-dashboard-theme', 'dark');
    await page.screenshot({ path: 'storage/app/phase06-intelligence-desktop.png' });
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const dimensions = await page.locator('.intelligence-filter select:visible').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().height));
        expect(dimensions.every(height => height >= 43)).toBe(true);
    }
    await page.screenshot({ path: 'storage/app/phase06-intelligence-mobile.png' });
    const pngDownload = page.waitForEvent('download');
    await growth.getByRole('button', { name: 'PNG', exact: true }).click();
    expect(readFileSync(await (await pngDownload).path()).subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
    await page.getByLabel('From', { exact: true }).fill('2000-01-01');
    await page.getByLabel('To', { exact: true }).fill('2000-01-31');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page.locator('.analytics-empty')).toHaveCount(6);
    expect(errors).toEqual([]);
});

test('institution analytics, KPI creation, report snapshots and scheduling work in browser', async ({ page }) => {
    test.setTimeout(300000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, 'admin@browser.example.test');
    await page.goto(`/intelligence?institution_id=${fixtures.institution}`);
    await expect(page.getByRole('heading', { name: 'Institutional Intelligence', exact: true })).toBeVisible();
    for (const section of ['projects', 'participation', 'programmes', 'opportunities', 'career', 'achievements', 'ai', 'quality']) {
        await page.goto(`/intelligence?institution_id=${fixtures.institution}&section=${section}`);
        await expect(page.getByRole('heading', { name: 'Institutional Intelligence', exact: true })).toBeVisible();
        await expect(page.locator('[data-analytics-status]')).toContainText('Analytics ready');
    }
    await page.goto(`/intelligence/institutions/${fixtures.institution}/kpis/create`);
    await page.getByLabel('Title', { exact: true }).fill('Browser reporting objective');
    await page.getByLabel('Change reason', { exact: true }).fill('Test-only institutional objective');
    await page.getByLabel('I confirm the scope, target and reporting period.').check();
    await page.getByRole('button', { name: 'Save KPI definition', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm change', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Browser reporting objective' })).toBeVisible();
    for (const format of ['csv','xlsx','pdf']) {
        await page.goto(`/reports/preview?institution_id=${fixtures.institution}&report_type=overview`);
        await page.getByRole('combobox', { name: 'Format', exact: true }).selectOption(format, { timeout: 10000 });
        await page.getByLabel('I confirm the reporting scope and confidentiality.').check();
        await page.getByRole('button', { name: 'Generate report', exact: true }).click();
        await page.getByRole('button', { name: 'Confirm change', exact: true }).click();
        const download = page.getByRole('link', { name: `Download ${format.toUpperCase()}`, exact: true });
        await expect(download).toBeVisible();
        const downloaded = page.waitForEvent('download');
        await download.click();
        const content = readFileSync(await (await downloaded).path());
        if (format === 'pdf') expect(content.subarray(0,5).toString()).toBe('%PDF-');
        if (format === 'xlsx') expect(content.subarray(0,2).toString()).toBe('PK');
        if (format === 'csv') expect(content.toString()).toContain('Institutional Innovation Overview');
    }
    await page.goto(`/reports/history?institution_id=${fixtures.institution}`);
    await expect(page.getByRole('heading', { name: 'Your private report snapshots', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Institutional Innovation Overview', exact: true })).toHaveCount(3);
    await page.goto(`/reports/schedules?institution_id=${fixtures.institution}`);
    await expect(page.getByText('not operationally verified', { exact: false }).first()).toBeVisible();
    await page.getByLabel('I confirm the recurrence, scope and authorised recipients.').check();
    await page.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm change', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Pause', exact: true }).first()).toBeVisible();
    expect(errors).toEqual([]);
});

test('students cannot open institutional intelligence or report exports', async ({ page }) => {
    await login(page, 'student@browser.example.test');
    expect((await page.goto(`/intelligence?institution_id=${fixtures.institution}`)).status()).toBe(403);
    expect((await page.goto(`/reports?institution_id=${fixtures.institution}`)).status()).toBe(403);
});
