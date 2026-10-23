import { test, expect } from '@playwright/test';

test('public and foundation layouts render at desktop tablet and mobile sizes', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    for (const width of [1440, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 960 });
        for (const path of ['/', '/about', '/features', '/for-students', '/for-institutions', '/contact', '/login', '/register', '/preview/auth', '/preview/personal', '/preview/institution', '/preview/components']) {
            await page.goto(path);
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('h1')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path + ' at ' + width).toBe(true);
            expect(await page.locator('img').evaluateAll(images => images.filter(image => !image.loading || image.loading !== 'lazy').every(image => image.complete && image.naturalWidth > 0))).toBe(true);
            if (['/', '/features', '/contact', '/login'].includes(path)) {
                await page.screenshot({ path: 'storage/app/phase002-' + width + '-' + (path === '/' ? 'home' : path.slice(1)) + '.png', fullPage: true });
            }
        }
    }
    await page.getByRole('button', { name: 'Open confirmation' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(errors).toEqual([]);
});

test('desktop links and mobile disclosure navigation work with keyboard controls', async ({ page }) => {
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
    await expect(page).toHaveURL(/\/register$/);
});

test('registration login logout and contact submission work with real CSRF protection', async ({ page, request }) => {
    await page.goto('/register');
    const email = 'browser-' + Date.now() + '@example.test';
    await page.getByLabel('Name', { exact: true }).fill('Browser Student');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('StrongPassword123');
    await page.getByLabel('Confirm password').fill('StrongPassword123');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText('Your account is active.')).toBeVisible();
    const privateResponse = await page.goto('/dashboard');
    expect(privateResponse.headers()['cache-control']).toContain('no-store');
    expect(await page.evaluate(async () => {
        for (const key of await caches.keys()) {
            if (await (await caches.open(key)).match('/dashboard')) return true;
        }
        return false;
    })).toBe(false);
    await page.getByRole('button', { name: 'Log out' }).click();
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('StrongPassword123');
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('button', { name: 'Log out' }).click();
    await page.goto('/contact');
    await page.getByLabel('Name', { exact: true }).fill('Browser Student');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Enquiry category').selectOption('student');
    await page.getByLabel('Subject', { exact: true }).fill('Browser verification');
    await page.getByLabel('Message', { exact: true }).fill('This is a browser test enquiry in an isolated SQLite database.');
    await page.getByLabel('I agree that my name').check();
    await page.getByRole('button', { name: 'Submit enquiry' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Email delivery was simulated' })).toBeVisible();
    const rejected = await request.post('/contact', { form: { name: 'Missing CSRF' } });
    expect(rejected.status()).toBe(419);
});

test('service worker keeps private pages out of caches and provides branded offline fallback', async ({ page, context }) => {
    await page.goto('/');
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
        if (!navigator.serviceWorker.controller) {
            await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }));
        }
    });
    for (const path of ['/about', '/contact', '/login', '/register', '/dashboard']) await page.goto(path);
    const entries = await page.evaluate(async () => {
        const keys = (await caches.keys()).filter(key => key.startsWith('ideyra-static-'));
        return Promise.all(keys.map(async key => (await (await caches.open(key)).keys()).map(request => new URL(request.url).pathname)));
    });
    expect(entries.length).toBe(1);
    expect(entries[0]).toContain('/offline.html');
    expect(entries[0]).toContain('/assets/brand/pwa-192x192.png');
    expect(entries[0].some(path => path.endsWith('.woff2'))).toBe(true);
    expect(entries[0].every(path => path === '/offline.html' || path.startsWith('/build/assets/') || path.startsWith('/assets/brand/'))).toBe(true);
    await context.setOffline(true);
    for (const path of ['/about', '/features', '/for-students', '/for-institutions', '/contact', '/dashboard']) {
        await page.goto(path);
        await expect(page.getByRole('heading', { name: 'A little pause. Your next idea can wait.' })).toBeVisible();
        expect(await page.locator('img').evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    }
    await page.screenshot({ path: 'storage/app/phase002-offline.png', fullPage: true });
    await context.setOffline(false);
    await page.getByRole('link', { name: 'Try again' }).click();
    await expect(page.getByRole('heading', { name: 'Your ideas deserve more than a notebook.' })).toBeVisible();
});
