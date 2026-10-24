import { test, expect } from '@playwright/test';

async function login(page,email) {
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill(email);
    await page.getByLabel('Password',{exact:true}).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button',{name:'Log In',exact:true}).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
test('AI coach unavailable state, private history and reviewed insights work on mobile and dark mode',async({page})=>{
    test.setTimeout(150000);
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
    const fixtures=JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
    await login(page,'student@browser.example.test');
    await page.goto('/ai');
    await expect(page.getByRole('button',{name:'Ask Career Explorer',exact:true})).toBeDisabled();
    await page.getByRole('button',{name:'Which assumptions should I test?',exact:true}).click();
    await expect(page.getByLabel('Your question',{exact:true})).toHaveValue('Which assumptions should I test?');
    await page.goto('/ai/conversations/'+fixtures.ai_conversation);
    await expect(page.getByText('Test fixture: reflect on your learning goal.',{exact:true})).toBeVisible();
    for(const width of [1440,768,390,360]) {
        await page.setViewportSize({width,height:850});
        await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
        if(width===1440) await page.screenshot({path:'storage/app/phase05-coach-desktop.png'});
    }
    await page.getByRole('button',{name:'Switch to dark mode',exact:true}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await page.screenshot({path:'storage/app/phase05-coach-mobile.png'});
    await page.getByText('Review, edit or save this suggestion',{exact:true}).click();
    await page.getByLabel('Reviewed text',{exact:true}).fill('Reviewed browser insight.');
    await page.getByLabel('I reviewed this text and confirm this specific change.',{exact:true}).check();
    await page.getByRole('button',{name:'Save reviewed choice',exact:true}).click();
    await page.getByRole('dialog').getByRole('button',{name:'Confirm change',exact:true}).click();
    await expect(page.getByText('Your reviewed choice has been saved.',{exact:true})).toBeVisible();
    await page.getByText('Saved insights · 1',{exact:true}).click();
    await expect(page.getByText('Reviewed browser insight.',{exact:false}).last()).toBeVisible();
    await page.goto('/ai/activity');
    await expect(page.locator('[data-chart] canvas')).toHaveCount(3);
    await expect(page.locator('[data-chart] canvas').first()).toBeVisible();
    await page.goto('/ai/activity?from=2099-01-01&to=2099-02-01');
    await expect(page.getByText('No matching data in this period.',{exact:false}).first()).toBeVisible();
    expect(errors).toEqual([]);
});
test('AI management exposes configuration status and institution controls without private conversations',async({page})=>{
    test.setTimeout(150000);
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const fixtures=JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
    await login(page,'system@browser.example.test');
    await page.goto('/system/ai');
    await expect(page.getByRole('heading',{name:'Configuration required',exact:true})).toBeVisible();
    await page.goto('/ai/activity?scope=system');
    await expect(page.getByRole('heading',{name:'Usage by institution',exact:true})).toBeVisible();
    await expect(page.locator('[data-chart] canvas')).toHaveCount(4);
    await expect(page.getByText('Test fixture: reflect on your learning goal.',{exact:true})).toHaveCount(0);
    await page.goto('/ai/conversations/'+fixtures.ai_conversation);
    await expect(page.getByText('403',{exact:true})).toBeVisible();
    expect(errors).toEqual([]);
});


test('AI request errors preserve the question and stop-waiting resets the controls',async({page})=>{
    test.setTimeout(90000);
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await login(page,'student@browser.example.test');
    await page.goto('/ai');
    // This test exercises the browser component with a test-only transport; the real provider stays disabled.
    await page.route('**/ai/conversations',route=>route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{message:['Test fixture: provider temporarily unavailable.']}})}));
    const ask=page.getByRole('button',{name:'Ask Career Explorer',exact:true});
    await ask.evaluate(button=>button.disabled=false);
    await page.getByLabel('Your question',{exact:true}).fill('Keep this private question in the browser.');
    await page.getByLabel('I authorise sending this question and the context I selected for this interaction.',{exact:true}).check();
    await ask.click();
    await expect(page.getByRole('alert').filter({hasText:'Test fixture: provider temporarily unavailable.'})).toBeVisible();
    await expect(page.getByLabel('Your question',{exact:true})).toHaveValue('Keep this private question in the browser.');
    await expect(ask).toBeEnabled();
    await page.unroute('**/ai/conversations');
    await page.route('**/ai/conversations',async route=>{
        await new Promise(resolve=>setTimeout(resolve,2500));
        await route.fulfill({status:503,body:'test fixture'}).catch(()=>{});
    });
    await ask.click();
    await expect(page.getByRole('button',{name:'Stop waiting',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Stop waiting',exact:true}).click();
    await expect(page.getByRole('alert').filter({hasText:'Stopped waiting.'})).toBeVisible();
    await expect(ask).toBeEnabled();
    expect(errors).toEqual([]);
});

