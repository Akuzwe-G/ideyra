import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('system analytics render with keyboard controls, theme, responsive layouts and real exports', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await login(page, 'system@browser.example.test');
    await page.goto('/system');
    await expect(page.locator('[data-chart-ready=true]')).toHaveCount(5);
    const line = page.locator('[data-chart]').filter({ has: page.getByRole('heading', { name: 'Applications over time', exact: true }) });
    await line.locator('canvas').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-analytics-status]')).toContainText('Applications over time:');
    const legend = page.getByRole('button', { name: /^Submitted: \d/ });
    await legend.focus();
    await page.keyboard.press('Enter');
    await expect(legend).toHaveAttribute('aria-pressed', 'false');
    await page.keyboard.press('Enter');
    await expect(legend).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    await expect(page.locator('.workspace')).toHaveAttribute('data-dashboard-theme', 'dark');
    expect(await page.locator('.workspace').evaluate(node => getComputedStyle(node).getPropertyValue('--surface').trim())).toBe('#192741');
    await page.emulateMedia({ reducedMotion: 'reduce', colorScheme: 'light' });
    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await expect(page.locator('.workspace')).toHaveAttribute('data-dashboard-theme', 'light');
    for (const width of [1440, 1024, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await expect(line.locator('canvas')).toBeVisible();
        await page.screenshot({ path: `storage/app/phase015-system-${width}.png`, fullPage: true });
    }
    const pngPromise = page.waitForEvent('download');
    await line.getByRole('button', { name: /Export PNG/ }).click();
    const png = await pngPromise;
    expect(png.suggestedFilename()).toBe('ideyra-applications.png');
    expect(readFileSync(await png.path()).subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
    const csvPromise = page.waitForEvent('download');
    await line.getByRole('link', { name: /Export CSV/ }).click();
    const csv = await csvPromise;
    expect(readFileSync(await csv.path(), 'utf8')).toContain('Applications');
    await line.getByText('View data table — Applications over time', { exact: true }).click();
    await expect(line.getByRole('table')).toBeVisible();
    await page.getByLabel('From', { exact: true }).fill('2000-01-01');
    await page.getByLabel('To', { exact: true }).fill('2000-01-31');
    await page.getByLabel('Time grouping').selectOption('weekly');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/group=weekly/);
    await expect(page.locator('.analytics-empty')).toHaveCount(5);
    await expect(page.locator('canvas')).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('institution analytics render every scoped chart while student export is denied', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await login(page, 'admin@browser.example.test');
    const institution = JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES).institution;
    await page.goto(`/institutions/${institution}/workspace`);
    await expect(page.locator('[data-chart-ready=true]')).toHaveCount(6);
    for (const theme of ['light', 'dark']) {
        if ((await page.locator('html').getAttribute('data-theme')) !== theme) {
            await page.getByRole('button', { name: theme === 'dark' ? 'Switch to dark mode' : 'Switch to light mode' }).click();
        }
        await page.setViewportSize({ width: 390, height: 900 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `storage/app/phase015-institution-${theme}.png`, fullPage: true });
    }
    const csv = await page.request.get(`/institutions/${institution}/analytics/composition/csv`);
    expect(csv.status()).toBe(200);
    expect(await csv.text()).toContain('Students,1');
    expect((await page.request.get('/system/analytics/countries/csv')).status()).toBe(403);
    await page.locator('[data-profile-menu]>summary').click();
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await login(page, 'student@browser.example.test');
    await page.goto(`/institutions/${institution}/workspace`);
    await expect(page.locator('[data-analytics]')).toHaveCount(0);
    expect((await page.request.get(`/institutions/${institution}/analytics/composition/csv`)).status()).toBe(403);
    // Expected access denials are HTTP responses, not JavaScript failures.
    expect(errors.filter(error => !error.includes('403'))).toEqual([]);
});

test('analytics summaries filters and tables remain usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await login(page, 'system@browser.example.test');
    await page.goto('/system');
    await page.getByText('View data table — Application status', { exact: true }).click();
    await expect(page.getByRole('table').filter({ has: page.getByRole('columnheader', { name: 'Share' }) })).toBeVisible();
    await page.getByLabel('Time grouping').selectOption('weekly');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/group=weekly/);
    await context.close();
});
