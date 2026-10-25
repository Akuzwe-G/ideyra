import { test, expect } from '@playwright/test';

async function login(page, email = 'system@browser.example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(email === 'system@browser.example.test' ? /\/system$/ : /\/dashboard$/);
}

test('login fits common screens vertically and supports password visibility', async ({ page }) => {
    for (const [width, height] of [[1440, 900], [1366, 768], [1024, 768], [768, 1024], [390, 844], [360, 640]]) {
        await page.setViewportSize({ width, height });
        await page.goto('/login');
        await expect(page.getByRole('heading', { name: 'Welcome back.' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Log In', exact: true })).toBeInViewport();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const dimensions = await page.evaluate(() => ({ height: document.documentElement.scrollHeight, viewport: innerHeight, layout: document.querySelector('.auth-layout').getBoundingClientRect().height, aside: document.querySelector('.auth-story').getBoundingClientRect().height, main: document.querySelector('.auth-content').getBoundingClientRect().height }));
        expect(dimensions.height, JSON.stringify(dimensions)).toBeLessThanOrEqual(height + 1);
        await page.screenshot({ path: 'storage/app/premium-login-' + width + '.png', fullPage: true });
    }
    await page.getByRole('button', { name: 'Show password' }).click();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'text');
    await page.getByRole('button', { name: 'Hide password' }).click();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'password');
});

test('desktop shell collapses with real icons and persists one theme across pages', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page);
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect(page.getByRole('heading',{name:'System Administration',exact:true})).toBeVisible();
        await expect(page.locator('.app-navigation .nav-item').first()).toContainText('Dashboard');
        await expect(page.locator('.app-navigation')).not.toContainText('Personal workspace');
        await expect(page.locator('.app-navigation')).not.toContainText('Idea Vault');
        expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBe(false);
        await page.screenshot({ path: 'storage/app/administration-dashboard-' + width + '.png', fullPage: false });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/system');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('.app-navigation .nav-item .icon')).toHaveCount(await page.locator('.app-navigation .nav-item').count());
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');
    await expect(page.locator('.brand-collapsed')).toBeVisible();
    await page.locator('.app-navigation a[aria-label="Applications"]').focus();
    await expect(page.locator('.app-navigation a[aria-label="Applications"] .nav-tooltip')).toBeVisible();
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    await page.goto('/system/applications');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');
    const sidebarLayout = await page.locator('#app-sidebar').evaluate(node => ({ width: node.getBoundingClientRect().width, x: node.getBoundingClientRect().x, styleWidth: getComputedStyle(node).width, scrollX, scrollWidth: document.documentElement.scrollWidth, viewport: innerWidth, navigation: node.querySelector('nav').getBoundingClientRect().toJSON() }));
    expect(sidebarLayout.width, JSON.stringify(sidebarLayout)).toBe(78);
    expect(sidebarLayout.x, JSON.stringify(sidebarLayout)).toBe(0);
    expect(sidebarLayout.scrollWidth, JSON.stringify(sidebarLayout)).toBeLessThanOrEqual(1440);
    await page.screenshot({ path: 'storage/app/premium-collapsed-dark.png', fullPage: true, animations: 'disabled' });
    const afterCapture = await page.locator('#app-sidebar').evaluate(node => ({ width: node.getBoundingClientRect().width, x: node.getBoundingClientRect().x, main: document.querySelector('.workspace-body').getBoundingClientRect().x, scrollX, brand: node.querySelector('.brand-collapsed').getBoundingClientRect().toJSON(), icon: node.querySelector('.nav-item svg').getBoundingClientRect().toJSON() }));
    expect(afterCapture.width, JSON.stringify(afterCapture)).toBe(78);
    expect(afterCapture.main, JSON.stringify(afterCapture)).toBe(78);
    expect(afterCapture.icon.x, JSON.stringify(afterCapture)).toBeGreaterThanOrEqual(0);
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
    await page.locator('[data-profile-menu]>summary').click();
    await expect(page.locator('.profile-dropdown')).toContainText('system@browser.example.test');
    await expect(page.locator('.profile-dropdown')).toContainText('System Administrator');
    await page.getByRole('link', { name: 'Change Password', exact: true }).click();
    await expect(page).toHaveURL(/\/profile#change-password$/);
    await expect(page.locator('#change-password')).toBeInViewport();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    for (const width of [1024, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
    expect(errors).toEqual([]);
});

test('mobile drawer supports focus, Escape and route navigation at tablet and phone widths', async ({ page }) => {
    await login(page, 'admin@browser.example.test');
    const institution = JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES).institution;
    for (const width of [768, 390, 360]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/institutions/' + institution + '/workspace');
        await expect(page.locator('[data-sidebar-toggle]')).toHaveAttribute('aria-expanded', 'false');
        await page.getByRole('button', { name: 'Toggle navigation' }).click();
        await expect(page.getByRole('dialog', { name: 'Workspace navigation' })).toBeVisible();
        await expect(page.locator('#app-sidebar')).toContainText('Institution Administrator');
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-sidebar-toggle]')).toBeFocused();
        await expect(page.locator('[data-sidebar-toggle]')).toHaveAttribute('aria-expanded', 'false');
        await page.getByRole('button', { name: 'Toggle navigation' }).click();
        await page.locator('#app-sidebar').getByRole('link', { name: 'Notifications', exact: true }).click();
        await expect(page).toHaveURL(/\/notifications$/);
        await expect(page.locator('[data-sidebar-toggle]')).toHaveAttribute('aria-expanded', 'false');
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: 'storage/app/premium-notifications-' + width + '.png', fullPage: true });
    }
});

test('review confirmation, notification read state and profile picture updates work end to end', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const application = JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES).application;
    await page.goto('/system/applications/' + application);
    await page.getByLabel('Review status', { exact: true }).selectOption('under_review');
    await page.getByRole('button', { name: 'Record review', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Confirm this change' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Go back' }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('button', { name: 'Record review', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm change' }).click();
    await expect(page.getByText('Review recorded.', { exact: false })).toBeVisible();
    await page.goto('/notifications');
    const row = page.locator('.notification-item').filter({ hasText: 'Application review recorded' }).first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Mark as read', exact: true }).click();
    await expect(row.getByRole('button', { name: 'Mark as unread', exact: true })).toBeVisible();
    await page.goto('/profile');
    await page.getByLabel('Profile picture', { exact: true }).setInputFiles('public/assets/brand/ideyra-icon.png');
    await page.getByRole('button', { name: 'Save my profile', exact: true }).click();
    await expect(page.locator('.profile-overview .user-avatar img')).toBeVisible();
    await page.getByLabel('Remove my current picture', { exact: true }).check();
    await page.getByRole('button', { name: 'Save my profile', exact: true }).click();
    await expect(page.locator('.profile-overview .user-avatar img')).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('search and filter controls share the brand design across roles, themes and screen widths', async ({page})=>{
    test.setTimeout(240000);
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await login(page,'student@browser.example.test');
    const inspectFilters=async(paths)=>{
        for(const width of [1440,768,390]) {
            await page.setViewportSize({width,height:1000});
            for(const path of paths) {
                await page.goto(path);
                const form=page.locator('form.innovation-filters, form.filter-grid, form.inline-search').first();
                await expect(form).toBeVisible();
                const controls=await form.locator('input:not([type=hidden]):not([type=checkbox]),select').evaluateAll(nodes=>nodes.map(node=>{
                    const style=getComputedStyle(node);const rect=node.getBoundingClientRect();
                    return {height:rect.height,border:style.borderTopWidth,radius:parseFloat(style.borderRadius),background:style.backgroundColor,search:node.name==='q',dropdown:node.tagName==='SELECT',start:parseFloat(style.paddingInlineStart),end:parseFloat(style.paddingInlineEnd)};
                }));
                expect(controls.length).toBeGreaterThan(0);
                for(const control of controls) {
                    expect(control.height,path).toBe(44);
                    expect(control.border,path).toBe('1px');
                    expect(control.radius,path).toBeGreaterThanOrEqual(6);
                    if(control.search) expect(control.start,path).toBeGreaterThanOrEqual(32);
                    if(control.dropdown) expect(control.end,path).toBeGreaterThanOrEqual(30);
                }
                expect(new Set(controls.map(control=>control.background)).size).toBe(1);
                expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),path).toBe(true);
                if(path==='/ideas'||path==='/projects'||path==='/groups'||path==='/system/applications')
                    await page.screenshot({path:'storage/app/filter-design-'+path.replaceAll('/','-')+'-'+width+'.png'});
            }
        }
    };
    await inspectFilters(['/ideas','/groups','/projects','/opportunities','/career/cvs','/career?tab=skill','/sponsorships','/opportunity-applications']);
    await page.goto('/ideas');
    await page.getByLabel('Search ideas',{exact:true}).fill('Design review filter');
    await page.locator('form.innovation-filters').getByRole('button',{name:'Search',exact:true}).click();
    await expect(page).toHaveURL(/q=Design(?:\+|%20)review(?:\+|%20)filter/);
    await expect(page.getByLabel('Search ideas',{exact:true})).toHaveValue('Design review filter');
    await page.getByRole('button',{name:'Switch to dark mode',exact:true}).click();
    await inspectFilters(['/ideas']);
    await page.locator('[data-profile-menu]>summary').click();
    await page.getByRole('button',{name:'Log out',exact:true}).click();
    await login(page,'system@browser.example.test');
    await expect(page.locator('.app-navigation .nav-item').first()).toContainText('Dashboard');
    await inspectFilters(['/system/applications','/system/users','/system/institutions']);
    expect(errors).toEqual([]);
});
