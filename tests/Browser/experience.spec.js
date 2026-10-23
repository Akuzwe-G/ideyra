import { test, expect } from '@playwright/test';

test('journey follows scrolling and feature previews support touch and keyboard at all widths', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const width of [1440, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/');
        const showcase = page.locator('[data-showcase]');
        await expect(showcase).toHaveAttribute('data-enhanced', 'true');
        const tabs = showcase.getByRole('tab');
        await expect(tabs).toHaveCount(6);
        for (let index = 0; index < 6; index++) {
            await tabs.nth(index).click();
            await expect(tabs.nth(index)).toHaveAttribute('aria-selected', 'true');
            await expect(showcase.getByRole('tabpanel')).toHaveCount(1);
            await expect(showcase.getByRole('tabpanel')).toContainText('Illustrative preview');
        }
        await tabs.first().focus();
        await page.keyboard.press('ArrowDown');
        await expect(tabs.nth(1)).toBeFocused();
        await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
        const story = page.locator('[data-story]');
        for (let index = 0; index < 5; index++) {
            await story.locator('[data-story-step]').nth(index).evaluate(element => element.scrollIntoView({ block: 'center', behavior: 'instant' }));
            await expect(story).toHaveAttribute('data-step', String(index));
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await story.screenshot({ path: 'storage/app/phase003-' + width + '-journey.png' });
        await showcase.scrollIntoViewIfNeeded();
        await showcase.screenshot({ path: 'storage/app/phase003-' + width + '-showcase.png' });
    }
    expect(errors).toEqual([]);
});

test('reduced motion disables native animation and unenhanced HTML stays readable', async ({ browser }) => {
    const reducedContext = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 390, height: 844 } });
    const page = await reducedContext.newPage();
    await page.goto('http://127.0.0.1:8011/');
    await expect(page.locator('[data-showcase]')).toHaveAttribute('data-enhanced', 'true');
    expect(await page.evaluate(() => document.getAnimations().filter(animation => animation.playState === 'running').length)).toBe(0);
    await expect(page.locator('.story-scene')).not.toHaveClass(/scene-enhanced/);
    await page.getByRole('tab', { name: /Portfolio/ }).click();
    await expect(page.getByRole('tabpanel')).toContainText('Private drafts stay private');
    await reducedContext.close();

    const plainContext = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 360, height: 844 } });
    const plain = await plainContext.newPage();
    await plain.goto('http://127.0.0.1:8011/');
    await expect(plain.locator('h1')).toBeVisible();
    await expect(plain.locator('.feature-panel:visible')).toHaveCount(6);
    await expect(plain.locator('.story-chapter:visible')).toHaveCount(5);
    await plain.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Features', exact: true }).click();
    await expect(plain).toHaveURL(/\/features$/);
    await expect(plain.locator('.feature-panel:visible')).toHaveCount(6);
    expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await plainContext.close();
});

test('experience cleans up on navigation and restores after a DOM replacement', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-showcase]')).toHaveAttribute('data-enhanced', 'true');
    await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigating')));
    await expect(page.locator('.feature-panel:visible')).toHaveCount(6);
    await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigated')));
    await expect(page.locator('[data-showcase]')).toHaveAttribute('data-enhanced', 'true');
    await page.evaluate(() => {
        const showcase = document.querySelector('[data-showcase]');
        const replacement = showcase.cloneNode(true);
        replacement.removeAttribute('data-enhanced');
        showcase.replaceWith(replacement);
    });
    await expect(page.locator('[data-showcase]')).toHaveAttribute('data-enhanced', 'true');
    await page.getByRole('tab', { name: /CV Builder/ }).click();
    await expect(page.getByRole('tabpanel')).toContainText('document you choose to share');
});

test('offline contact form is not submitted or queued and values remain available', async ({ page, context }) => {
    await page.goto('/contact');
    await page.getByLabel('Name', { exact: true }).fill('Offline Test');
    await page.getByLabel('Email', { exact: true }).fill('offline@example.test');
    await page.getByLabel('Enquiry category').selectOption('feedback');
    await page.getByLabel('Subject', { exact: true }).fill('Keep this message');
    await page.getByLabel('Message', { exact: true }).fill('This enquiry should remain on this page until I submit it online.');
    await page.getByLabel('I agree that my name').check();
    await context.setOffline(true);
    await page.getByRole('button', { name: 'Submit enquiry' }).click();
    await expect(page.locator('[data-submit-feedback]')).toContainText('will not be queued');
    await expect(page.getByLabel('Message', { exact: true })).toHaveValue(/This enquiry should remain/);
    await expect(page.getByRole('button', { name: 'Submit enquiry' })).toBeEnabled();
    await context.setOffline(false);
});
