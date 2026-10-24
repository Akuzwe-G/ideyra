import { test, expect } from '@playwright/test';
const fixture = () => JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('both application wizards validate review and produce private real PDFs', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 390, height: 844 });
    for (const type of ['institution', 'student']) {
        await page.goto('/apply/' + type);
        const form = page.locator('[data-application-wizard]');
        await expect(form).toHaveAttribute('data-wizard-ready', 'true');
        await page.getByRole('button', { name: 'Continue' }).click();
        await expect(form.locator('[data-wizard-progress]')).toContainText('Step 1');
        const total = await form.locator('[data-wizard-step]').count();
        for (let step = 0; step < total - 1; step++) {
            const controls = form.locator('[data-wizard-step]').nth(step).locator('input,select,textarea');
            for (let index = 0; index < await controls.count(); index++) {
                const control = controls.nth(index);
                if (!await control.isVisible() || !await control.isEnabled()) continue;
                const tag = await control.evaluate(node => node.tagName.toLowerCase());
                const kind = await control.getAttribute('type');
                const name = await control.getAttribute('name');
                if (tag === 'select') {
                    const value = await control.locator('option').nth(1).getAttribute('value');
                    await control.selectOption(value);
                } else if (kind === 'checkbox') await control.check();
                else if (kind === 'email') await control.fill(type + '-applicant@browser.example.test');
                else if (kind === 'url') await control.fill('https://example.test');
                else if (kind === 'number') await control.fill(name === 'graduation_year' ? '2029' : '100');
                else await control.fill('Browser response for ' + name);
            }
            await page.getByRole('button', { name: 'Continue' }).click();
            await expect(form.locator('[data-wizard-progress]')).toContainText('Step ' + (step + 2));
        }
        await expect(form.locator('[data-wizard-review]')).toContainText('Browser response');
        await page.getByLabel('I have reviewed the information').check();
        await page.getByLabel('I agree to the secure storage').check();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: 'storage/app/phase01-' + type + '-review.png', fullPage: true });
        await page.getByRole('button', { name: 'Submit application' }).click();
        await expect(page.getByRole('heading', { name: 'Thank you. Your application has been received.' })).toBeVisible();
        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download Application PDF' }).click();
        expect((await download).suggestedFilename()).toMatch(/^IDEYRA-.*\.pdf$/);
        await expect(page.getByText('No user account was created.', { exact: false })).toBeVisible();
    }
});

test('System Administrator reviews an application and explicitly creates a draft institution', async ({ page }) => {
    await login(page, 'system@browser.example.test');
    await page.goto('/system/applications/' + fixture().application);
    await page.getByLabel('Review status', { exact: true }).selectOption('approved');
    await page.getByLabel('Private internal note').fill('Private browser reviewer note.');
    await page.getByRole('button', { name: 'Record review' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Confirm change' }).click();
    await page.getByRole('link', { name: 'Begin explicit institution onboarding' }).click();
    await page.getByLabel('Unique institution slug').fill('browser-onboarded-' + Date.now());
    await page.getByRole('button', { name: 'Save institution profile' }).click();
    await expect(page.getByText('Institution created as draft.', { exact: false })).toBeVisible();
    await expect(page.getByLabel('Institution status', { exact: true })).toHaveValue('draft');
    await page.getByRole('link', { name: 'Create or appoint an Institution Administrator' }).click();
    await page.getByLabel('Full name', { exact: true }).fill('Invited Institution Administrator');
    await page.getByLabel('Email address', { exact: true }).fill('new-admin@browser.example.test');
    await page.getByLabel('Initial institutional role').selectOption({ label: 'Institution Administrator' });
    await page.getByRole('button', { name: 'Prepare secure invitation' }).click();
    await expect(page.getByText('Membership is pending acceptance.', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: /activate/i })).toHaveCount(0);
});

test('institution administrator manages academic structure assignments and scoped permissions', async ({ page }) => {
    test.setTimeout(90000);
    await login(page, 'admin@browser.example.test');
    const institution = fixture().institution;
    const prefix = '/institutions/' + institution + '/academic/';
    await page.goto(prefix + 'years');
    await page.getByLabel('Name', { exact: true }).fill('Browser academic year');
    await page.getByLabel('Start date', { exact: true }).fill('2026-08-01');
    await page.getByLabel('End date', { exact: true }).fill('2027-07-31');
    await page.getByRole('button', { name: 'Save academic record' }).click();
    await page.goto(prefix + 'programmes');
    await page.getByLabel('Name', { exact: true }).fill('Browser Engineering');
    await page.getByLabel('Programme code').fill('BENG');
    await page.getByRole('button', { name: 'Save academic record' }).click();
    await page.goto(prefix + 'intakes');
    await page.getByLabel('Name', { exact: true }).fill('Browser intake');
    await page.getByLabel('Start date', { exact: true }).fill('2026-08-01');
    await page.getByLabel('Academic year', { exact: true }).selectOption({ label: 'Browser academic year' });
    await page.getByRole('button', { name: 'Save academic record' }).click();
    await page.goto(prefix + 'classes');
    await page.getByLabel('Name', { exact: true }).fill('Browser class');
    await page.getByLabel('Academic year', { exact: true }).selectOption({ label: 'Browser academic year' });
    await page.getByLabel('Programme', { exact: true }).selectOption({ label: 'Browser Engineering' });
    await page.getByLabel('Intake', { exact: true }).selectOption({ label: 'Browser intake' });
    await page.getByRole('button', { name: 'Save academic record' }).click();
    await page.getByRole('row').filter({ hasText: 'Browser class' }).getByRole('link', { name: 'Staff & student assignments' }).click();
    await page.getByLabel('Active institutional member').selectOption({ label: 'Browser Student / Student' });
    await page.getByLabel('Assignment', { exact: true }).selectOption('class_representative');
    await page.getByRole('button', { name: 'Save class assignment' }).click();
    await expect(page.getByText('Class assignment saved.', { exact: true })).toBeVisible();
    await page.goto('/institutions/' + institution + '/members/' + fixture().student_member);
    await page.getByLabel('Override projects.approve', { exact: true }).selectOption('grant');
    await page.getByRole('button', { name: 'Save scoped permissions' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Confirm change' }).click();
    await expect(page.getByText('Scoped permissions saved.', { exact: false })).toBeVisible();
    for (const width of [1440, 768, 390, 360]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: 'storage/app/phase01-' + width + '-permissions.png', fullPage: true });
    }
    expect((await page.request.get('/system/institutions/create')).status()).toBe(403);
});
