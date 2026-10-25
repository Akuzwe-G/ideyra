import { test, expect } from '@playwright/test';
test.use({actionTimeout:20000});

async function login(page,email='student@browser.example.test') {
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill(email);
    await page.getByLabel('Password',{exact:true}).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button',{name:'Log In',exact:true}).click();
    await expect(page).toHaveURL(email === 'system@browser.example.test' ? /\/system$/ : /\/dashboard$/);
}
async function logout(page) {
    await page.locator('[data-profile-menu]>summary').click();
    await page.getByRole('button',{name:'Log out',exact:true}).click();
    await expect(page).toHaveURL(/\/$/);
}
async function confirm(page) {
    await page.getByRole('dialog').getByRole('button',{name:'Confirm change',exact:true}).click();
}
function errorsOn(page) {
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error') errors.push(message.text());});
    return errors;
}

test('professional profile, three CV PDFs and opt-in portfolio work across themes and mobile widths',async({page})=>{
    test.setTimeout(240000);
    const errors=errorsOn(page);
    await login(page);
    await page.goto('/career?tab=profile');
    await page.getByLabel('Professional display name',{exact:true}).fill('Browser Professional Student');
    await page.getByLabel('Professional headline',{exact:true}).fill('Research, engineering and thoughtful design');
    await page.getByLabel('Short biography',{exact:true}).fill('I build real academic projects and document my own contribution.');
    await page.getByLabel('Professional email (optional)',{exact:true}).fill('chosen@professional.example');
    await page.getByLabel('GitHub URL',{exact:true}).fill('https://github.com/example');
    await page.getByRole('button',{name:'Save professional profile',exact:true}).click();
    await expect(page.getByText(/Professional profile saved\./)).toBeVisible();
    await page.goto('/career?tab=skill');
    await page.getByLabel('Skill name',{exact:true}).fill('Browser research skill');
    await page.getByRole('combobox',{name:'Category',exact:true}).selectOption('Research');
    await page.getByLabel('Supporting evidence / description',{exact:true}).fill('Research performed in a real project.');
    await page.getByRole('button',{name:'Save skill',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Browser research skill',exact:true})).toBeVisible();

    for(const template of ['classic','modern','technical']) {
        await page.goto('/career/cvs/create?template='+template);
        await page.getByLabel('CV title',{exact:true}).fill('Browser '+template+' CV');
        await page.getByLabel('Contact information',{exact:true}).fill('Browser Professional Student\nhttps://github.com/example');
        await page.getByRole('textbox',{name:'Professional summary content',exact:true}).fill('Accurate browser CV summary.');
        await page.getByRole('button',{name:'Save CV version',exact:true}).click();
        await expect(page.getByText('Private CV saved with a new immutable version.',{exact:true})).toBeVisible();
        const frame=page.frameLocator('[name="cv-preview-frame"]');
        await expect(frame.getByText('Accurate browser CV summary.',{exact:true})).toBeVisible();
        await expect.poll(()=>frame.locator('.cv-document').evaluate(node=>node.getBoundingClientRect().width/window.innerWidth)).toBeLessThanOrEqual(1.01);
        await frame.getByRole('button',{name:'Actual size (100%)',exact:true}).click();
        await expect.poll(()=>frame.locator('.cv-document').evaluate(node=>Math.round(node.getBoundingClientRect().width))).toBe(794);
        await frame.getByRole('button',{name:'Fit page to screen',exact:true}).click();
        await expect.poll(()=>frame.locator('.cv-document').evaluate(node=>node.getBoundingClientRect().width/window.innerWidth)).toBeLessThanOrEqual(1.01);
        await page.locator('iframe[name="cv-preview-frame"]').screenshot({path:'storage/app/phase04-cv-frame-'+template+'.png'});
        const previewPage=await page.context().newPage();
        await previewPage.goto(page.url().replace('/edit','/preview'));
        await previewPage.locator('.cv-document').screenshot({path:'storage/app/phase04-cv-'+template+'.png'});
        await previewPage.close();
        const response=await page.request.get(page.url().replace('/edit','/preview')+'?pdf=1');
        expect(response.status()).toBe(200);
        const pdf=await response.body();
        expect(pdf.subarray(0,5).toString()).toBe('%PDF-');
        expect(pdf.length).toBeGreaterThan(5000);
        await page.getByRole('textbox',{name:'Professional summary content',exact:true}).fill('Near-live unsaved preview content.');
        await page.getByRole('button',{name:'Update preview',exact:true}).click();
        await expect(page.frameLocator('[name="cv-preview-frame"]').getByText('Near-live unsaved preview content.',{exact:true})).toBeVisible();
        await page.getByRole('textbox',{name:'Professional summary content',exact:true}).fill('Second near-live preview content.');
        await page.getByRole('button',{name:'Update preview',exact:true}).click();
        await expect(page.frameLocator('[name="cv-preview-frame"]').getByText('Second near-live preview content.',{exact:true})).toBeVisible();
        await page.setViewportSize({width:360,height:900});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await expect.poll(()=>frame.locator('.cv-document').evaluate(node=>node.getBoundingClientRect().width/window.innerWidth)).toBeLessThanOrEqual(1.01);
        await page.setViewportSize({width:1440,height:900});
    }
    await page.goto('/career/portfolio');
    await page.getByLabel('Public URL slug',{exact:true}).fill('browser-professional-student');
    await page.getByRole('checkbox',{name:'Skills',exact:true}).check();
    await page.getByRole('checkbox',{name:'Skills · Browser research skill',exact:true}).check();
    await page.getByRole('checkbox',{name:'Biography',exact:true}).check();
    await page.getByRole('button',{name:'Save private portfolio settings',exact:true}).click();
    await expect(page.getByText('Portfolio settings saved privately. Preview and explicitly publish when ready.',{exact:true})).toBeVisible();
    expect((await page.request.get('/p/browser-professional-student')).status()).toBe(404);
    await page.getByRole('checkbox',{name:/I reviewed the preview/}).check();
    await page.getByRole('button',{name:'Publish portfolio',exact:true}).click();
    await confirm(page);
    await expect(page.getByText('Portfolio published. Only explicitly selected information is public.',{exact:true})).toBeVisible();
    await page.goto('/p/browser-professional-student');
    await expect(page.getByRole('heading',{name:'Browser Professional Student',exact:true})).toBeVisible();
    await expect(page.getByRole('heading',{name:'Browser research skill',exact:true})).toBeVisible();
    await expect(page.locator('body')).not.toContainText('chosen@professional.example');
    await expect(page.locator('body')).not.toContainText('student@browser.example.test');
    for(const width of [1440,768,390,360]) {
        await page.setViewportSize({width,height:900});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await page.getByRole('button',{name:'Switch colour theme',exact:true}).or(page.getByRole('button',{name:/Switch to (light|dark) mode/})).click();
        await page.screenshot({path:'storage/app/phase04-portfolio-'+width+'.png',fullPage:true});
    }
    await page.setViewportSize({width:1440,height:900});
    await page.goto('/career');
    await expect(page.locator('[data-chart]').first().locator('canvas')).toBeVisible();
    expect(await page.locator('[data-chart]').count()).toBe(3);
    await page.goto('/career/portfolio');
    for(const theme of ['creative','technical','minimal']) {
        await page.getByRole('radio',{name:new RegExp(theme==='creative'?'Creative Innovator':theme==='technical'?'Technical Builder':'Ideyra Minimal')}).check();
        await page.getByRole('button',{name:'Save private portfolio settings',exact:true}).click();
        await page.getByRole('link',{name:'Preview selected information',exact:true}).click();
        await expect(page.locator('body')).toHaveClass(new RegExp('portfolio-'+theme));
        await page.goto('/career/portfolio');
    }
    await page.getByRole('checkbox',{name:/I reviewed the preview/}).check();
    await page.getByRole('button',{name:'Unpublish portfolio',exact:true}).click();
    await confirm(page);
    expect((await page.request.get('/p/browser-professional-student')).status()).toBe(404);
    expect(errors).toEqual([]);
});

test('independent institutional verification publishes a minimal reference and records revocation',async({page})=>{
    test.setTimeout(180000);
    const errors=errorsOn(page);
    await login(page);
    await page.goto('/career?tab=achievement');
    await page.getByLabel('Title',{exact:true}).fill('Browser research distinction');
    await page.getByLabel('Issuing organisation',{exact:true}).fill('Browser Institution');
    await page.getByLabel('Description',{exact:true}).fill('A real achievement fixture for verification.');
    await page.getByRole('button',{name:'Save achievement',exact:true}).click();
    await page.goto('/career/verifications');
    await page.getByRole('combobox',{name:'Profile record (choose this OR a project)',exact:true}).selectOption({label:'Browser research distinction · achievement'});
    await page.getByRole('combobox',{name:'Verification category',exact:true}).selectOption('achievement');
    await page.getByLabel('Evidence and explanation for the reviewer',{exact:true}).fill('Confidential fixture evidence for the authorised institution.');
    await page.getByRole('checkbox',{name:/Request permission for a minimal public verification reference/}).check();
    await page.getByRole('checkbox',{name:/I authorise sharing this selected evidence/}).check();
    await page.getByRole('button',{name:'Request verification',exact:true}).click();
    await expect(page.getByText('Verification requested. Uploads are not automatically verified.',{exact:true})).toBeVisible();
    await logout(page);
    await login(page,'admin@browser.example.test');
    await page.goto('/career/verifications');
    const review=page.locator('details.innovation-entry').filter({has:page.locator('summary').filter({hasText:'Browser research distinction · Browser Student'})});
    await review.locator('summary').first().click();
    await review.getByRole('combobox',{name:'Decision',exact:true}).selectOption('verified');
    await review.getByLabel('Decision reason / feedback',{exact:true}).fill('Confirmed independently by the institution.');
    await review.getByLabel('Evidence reviewed (private institutional decision record)',{exact:true}).fill('Private academic reviewer notes.');
    await review.getByRole('checkbox',{name:/Approve the requested public reference/}).check();
    await review.getByRole('button',{name:'Record verification decision',exact:true}).click();
    await expect(page.getByText('Verification decision recorded with evidence and decision history.',{exact:true})).toBeVisible();
    await logout(page);
    await login(page);
    await page.goto('/career/portfolio');
    await page.getByRole('checkbox',{name:'Achievements',exact:true}).check();
    await page.getByRole('checkbox',{name:'Achievements · Browser research distinction',exact:true}).check();
    await page.getByRole('button',{name:'Save private portfolio settings',exact:true}).click();
    await expect(page.getByText('Portfolio settings saved privately. Preview and explicitly publish when ready.',{exact:true})).toBeVisible();
    await page.getByRole('checkbox',{name:/I reviewed the preview/}).check();
    await page.getByRole('button',{name:'Publish portfolio',exact:true}).click();
    await confirm(page);
    await page.getByRole('link',{name:'Open public portfolio',exact:true}).getAttribute('href').then(url=>page.goto(url));
    const reference=await page.getByRole('link',{name:'Browser research distinction — check current status',exact:true}).getAttribute('href');
    await page.goto(reference);
    await expect(page.locator('main')).toContainText('Verified');
    await expect(page.locator('main')).not.toContainText('Private academic reviewer notes.');
    await expect(page.locator('main')).not.toContainText('Confidential fixture evidence');
    await page.goto('/dashboard');await logout(page);
    await login(page,'admin@browser.example.test');await page.goto('/career/verifications');
    const revoke=page.locator('details.innovation-entry').filter({has:page.locator('summary').filter({hasText:'Browser research distinction · Browser Student'})});
    await revoke.locator('summary').first().click();
    await revoke.getByRole('combobox',{name:'Decision',exact:true}).selectOption('revoked');
    await revoke.getByLabel('Decision reason / feedback',{exact:true}).fill('Verification revoked following an evidence update.');
    await revoke.getByLabel('Evidence reviewed (private institutional decision record)',{exact:true}).fill('Private revocation evidence.');
    await revoke.getByRole('button',{name:'Record verification decision',exact:true}).click();
    await page.goto(reference);
    await expect(page.locator('main')).toContainText('Revoked');
    await expect(page.locator('main')).not.toContainText('Private revocation evidence.');
    expect(errors).toEqual([]);
});

test('opt-in public contact saves an encrypted private inbox message without claiming email delivery',async({page})=>{
    test.setTimeout(150000);
    const errors=errorsOn(page);
    await login(page);
    await page.goto('/career/portfolio');
    await page.getByLabel('Public URL slug',{exact:true}).fill('browser-contact-owner');
    await page.getByRole('checkbox',{name:/Enable an opt-in public contact form/}).check();
    await page.getByRole('button',{name:'Save private portfolio settings',exact:true}).click();
    await expect(page.getByText('Portfolio settings saved privately. Preview and explicitly publish when ready.',{exact:true})).toBeVisible();
    await page.getByRole('checkbox',{name:/I reviewed the preview/}).check();
    await page.getByRole('button',{name:'Publish portfolio',exact:true}).click();
    await confirm(page);
    await expect(page.getByText('Portfolio published. Only explicitly selected information is public.',{exact:true})).toBeVisible();
    await logout(page);
    await page.goto('/p/browser-contact-owner');
    await page.getByLabel('Your name',{exact:true}).fill('Browser Contact Visitor');
    await page.getByLabel('Your email',{exact:true}).fill('visitor@browser.example.test');
    await page.getByRole('textbox',{name:'Professional enquiry',exact:true}).fill('A relevant professional enquiry about your project contribution.');
    await page.getByRole('checkbox',{name:/I agree to share my name/}).check();
    // The signed contact token deliberately rejects submissions in the first two seconds.
    await page.waitForTimeout(2200);
    await page.getByRole('button',{name:'Send professional enquiry',exact:true}).click();
    await expect(page.getByText('Message saved in the portfolio owner’s private Ideyra inbox. External email delivery is not enabled.',{exact:true})).toBeVisible();
    await expect(page.locator('body')).not.toContainText('student@browser.example.test');
    await login(page);
    await page.goto('/career/portfolio');
    await expect(page.getByRole('heading',{name:'Browser Contact Visitor',exact:true})).toBeVisible();
    await expect(page.getByText('A relevant professional enquiry about your project contribution.',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Delete message',exact:true}).click();
    await confirm(page);
    await expect(page.getByText('Private inbox updated.',{exact:true})).toBeVisible();
    await expect(page.getByRole('heading',{name:'Browser Contact Visitor',exact:true})).toHaveCount(0);
    expect(errors).toEqual([]);
});


