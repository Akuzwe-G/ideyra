import { test, expect } from '@playwright/test';

test('public and foundation layouts render at desktop tablet and mobile sizes', async ({ page }) => {
    test.setTimeout(150000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    for (const width of [1440, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 960 });
        for (const path of ['/', '/about', '/features', '/for-students', '/for-institutions', '/contact', '/login', '/apply', '/forgot-password', '/preview/auth', '/preview/personal', '/preview/institution', '/preview/components']) {
            await page.goto(path);
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('h1')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path + ' at ' + width).toBe(true);
            expect(await page.locator('img').evaluateAll(images => images.filter(image => image.loading !== 'lazy').every(image => image.complete && image.naturalWidth > 0))).toBe(true);
        }
    }
    await page.getByRole('button', { name: 'Open confirmation' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(errors).toEqual([]);
});

test('desktop links and mobile disclosure navigation lead to login and applications', async ({ page }) => {
    await page.goto('/');
    const nav = page.getByRole('navigation', { name: 'Main navigation' });
    for (const [name, path] of [['About', '/about'], ['Features', '/features'], ['For Students', '/for-students'], ['For Institutions', '/for-institutions'], ['Contact', '/contact']]) {
        await nav.getByRole('link', { name, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(path + '$'));
        await expect(nav.getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page');
    }
    await page.setViewportSize({ width: 390, height: 844 });
    const toggle = page.getByRole('button', { name: 'Menu' });
    await expect(nav).not.toBeVisible();
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(nav).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(nav).not.toBeVisible();
    await expect(toggle).toBeFocused();
    await toggle.click();
    await nav.getByRole('link', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/');
    await toggle.click();
    await nav.getByRole('link', { name: 'Get Started' }).click();
    await expect(page).toHaveURL(/\/apply$/);
});

test('administrator-issued login logout and contact submission retain CSRF protection', async ({ page, request }) => {
    expect((await request.post('/register', { form: { email: 'forbidden@example.test' } })).status()).toBe(404);
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('student@browser.example.test');
    await page.getByLabel('Password', { exact: true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Personal workspace', exact: true })).toBeVisible();
    const privateResponse = await page.goto('/dashboard');
    expect(privateResponse.headers()['cache-control']).toContain('no-store');
    expect(await page.evaluate(async () => {
        for (const key of await caches.keys()) if (await (await caches.open(key)).match('/dashboard')) return true;
        return false;
    })).toBe(false);
    expect((await page.request.get('/system')).status()).toBe(403);
    await page.locator('[data-profile-menu]>summary').click();
    await page.getByRole('button', { name: 'Log out' }).click();
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/contact');
    await page.getByLabel('Name', { exact: true }).fill('Browser Student');
    await page.getByLabel('Email', { exact: true }).fill('student@browser.example.test');
    await page.getByLabel('Enquiry category').selectOption('student');
    await page.getByLabel('Subject', { exact: true }).fill('Browser verification');
    await page.getByLabel('Message', { exact: true }).fill('This is a browser test enquiry in an isolated SQLite database.');
    await page.getByLabel('I agree that my name').check();
    await page.getByRole('button', { name: 'Submit enquiry' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Email delivery was simulated' })).toBeVisible();
    expect((await request.post('/contact', { form: { name: 'Missing CSRF' } })).status()).toBe(419);
});

test('service worker keeps identity application and institution pages out of caches', async ({ page, context }) => {
    await page.goto('/');
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
        if (!navigator.serviceWorker.controller) await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }));
    });
    for (const path of ['/about', '/contact', '/login', '/apply', '/dashboard']) await page.goto(path);
    const entries = await page.evaluate(async () => {
        const keys = (await caches.keys()).filter(key => key.startsWith('ideyra-static-'));
        return Promise.all(keys.map(async key => (await (await caches.open(key)).keys()).map(request => new URL(request.url).pathname)));
    });
    expect(entries.length).toBe(1);
    expect(entries[0]).toContain('/offline.html');
    expect(entries[0].every(path => path === '/offline.html' || path.startsWith('/build/assets/') || path.startsWith('/assets/brand/'))).toBe(true);
    await context.setOffline(true);
    for (const path of ['/apply', '/apply/institution', '/contact', '/dashboard', '/system']) {
        await page.goto(path);
        await expect(page.getByRole('heading', { name: 'A little pause. Your next idea can wait.' })).toBeVisible();
    }
    await context.setOffline(false);
    await page.getByRole('link', { name: 'Try again' }).click();
    await expect(page.getByRole('heading', { name: 'Your ideas deserve more than a notebook.' })).toBeVisible();
});
