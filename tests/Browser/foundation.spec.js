import { test, expect } from '@playwright/test';

test('desktop and mobile pages render without overflow or browser errors', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 960 });
        for (const path of ['/', '/preview/auth', '/preview/personal', '/preview/institution', '/preview/components']) {
            await page.goto(path);
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('h1')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await page.screenshot({ path: 'storage/app/phase00-' + width + '-' + (path === '/' ? 'landing' : path.split('/').pop()) + '.png', fullPage: true });
        }
    }
    await page.getByRole('button', { name: 'Open confirmation' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    expect(errors).toEqual([]);
});

test('service worker caches only public static files and serves offline fallback', async ({ page, context }) => {
    await page.goto('/');
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
        if (!navigator.serviceWorker.controller) {
            await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }));
        }
    });
    const entries = await page.evaluate(async () => {
        const keys = await caches.keys();
        return Promise.all(keys.filter(key => key.startsWith('ideyra-static-')).map(async key => {
            const cache = await caches.open(key);
            return (await cache.keys()).map(request => new URL(request.url).pathname);
        }));
    });
    expect(entries.length).toBe(1);
    expect(entries[0]).toContain('/offline.html');
    expect(entries[0].some(path => path.endsWith('.woff2'))).toBe(true);
    expect(entries[0].every(path => path === '/offline.html' || path.startsWith('/build/assets/'))).toBe(true);
    await page.goto('/preview/personal');
    const cachedNavigation = await page.evaluate(async () => {
        for (const key of await caches.keys()) {
            if (!key.startsWith('ideyra-static-')) continue;
            if (await (await caches.open(key)).match('/preview/personal')) return true;
        }
        return false;
    });
    expect(cachedNavigation).toBe(false);
    await context.setOffline(true);
    await page.goto('/preview/institution');
    await expect(page.getByRole('heading', { name: 'A little pause. Your next idea can wait.' })).toBeVisible();
    expect(await page.evaluate(() => getComputedStyle(document.body).fontFamily)).toContain('Montserrat');
    await page.screenshot({ path: 'storage/app/phase00-offline.png', fullPage: true });
    await context.setOffline(false);
    await page.getByRole('link', { name: 'Try again' }).click();
    await expect(page.getByRole('heading', { name: 'Imagine. Build. Achieve.' })).toBeVisible();
});
