import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

async function login(page, email='student@browser.example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact:true }).fill(email);
    await page.getByLabel('Password', { exact:true }).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button', { name:'Log In', exact:true }).click();
    await expect(page).toHaveURL(email === 'system@browser.example.test' ? /\/system$/ : /\/dashboard$/);
}
test('student captures a private idea and manages a linked project, task, board and analytics', async ({page}) => {
    test.setTimeout(180000);
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error') errors.push(message.text());});
    await login(page);
    await page.goto('/ideas/create');
    await page.getByLabel('Idea title').fill('Browser water idea');
    await page.getByLabel('Describe your idea').fill('Monitor water in remote areas.');
    await page.getByLabel('Problem or opportunity').fill('No timely quality measurements.');
    await page.getByLabel('Proposed solution').fill('Build a portable sensor.');
    await page.getByLabel('Intended beneficiaries').fill('Local communities.');
    await page.getByLabel('Category', {exact:true}).fill('Engineering');
    await page.getByRole('button',{name:'Save idea',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Browser water idea',exact:true})).toBeVisible();
    await expect(page.locator('.page-toolbar')).toContainText('private');
    await page.getByRole('link',{name:'Create linked project'}).click();
    await page.getByLabel('I confirm the selected ownership').check();
    await page.getByRole('button',{name:'Save project',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Browser water idea',exact:true})).toBeVisible();
    const projectUrl=page.url();
    await page.getByRole('link',{name:'Tasks',exact:true}).click();
    await page.locator('summary').filter({hasText:'Create task'}).click();
    await page.getByLabel('Task title').fill('Build sensor prototype');
    await page.getByLabel('Due date',{exact:true}).fill('2026-10-30');
    await page.getByLabel('Browser Student',{exact:true}).check();
    await page.getByRole('button',{name:'Create task',exact:true}).click();
    await page.getByRole('link',{name:'Build sensor prototype'}).click();
    await page.getByRole('combobox',{name:'Status',exact:true}).first().selectOption('in_progress');
    await page.getByLabel('Progress comment').fill('First sensor assembled.');
    await page.getByRole('button',{name:'Save progress'}).click();
    await expect(page.getByText('First sensor assembled.')).toBeVisible();
    await page.goto(projectUrl+'?tab=tasks&view=board');
    await expect(page.getByRole('region',{name:'Task Kanban board'})).toBeVisible();
    await expect(page.getByRole('link',{name:'Build sensor prototype'})).toBeVisible();
    for(const width of [1440,768,390,360]) {
        await page.setViewportSize({width,height:900});
        for(const tab of ['overview','tasks','milestones','deliverables','team','feedback','activity']) {
            await page.goto(projectUrl+'?tab='+tab);
            await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        }
        await page.goto(projectUrl+'?tab=overview');
        await page.screenshot({path:'storage/app/phase02-project-'+width+'.png',fullPage:true,animations:'disabled'});
    }
    await page.goto('/dashboard');
    await expect(page.locator('[data-chart-ready=true]')).toHaveCount(3);
    const status=page.locator('[data-chart]').filter({has:page.getByRole('heading',{name:'Project status',exact:true})});
    await status.locator('canvas').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-analytics-status]')).toContainText('Project status:');
    await page.getByRole('button',{name:'Switch to dark mode'}).click();
    await expect(page.locator('.workspace')).toHaveAttribute('data-dashboard-theme','dark');
    const download=page.waitForEvent('download');
    await status.getByRole('link',{name:/Export CSV/}).click();
    const csv=await download;
    expect(readFileSync(await csv.path(),'utf8')).toContain('Draft,1');
    expect(errors).toEqual([]);
});
test('institution administrator creates a class group and institution-owned project',async({page})=>{
    test.setTimeout(90000);
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{if(message.type()==='error') errors.push(message.text());});
    const fixture=JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
    await login(page,'admin@browser.example.test');
    await page.goto('/groups/create?institution_id='+fixture.institution);
    await page.getByLabel('Group name').fill('Browser academic group');
    await page.getByRole('combobox',{name:'Academic class',exact:true}).selectOption({index:1});
    await page.getByLabel('Browser Student',{exact:true}).check();
    await page.getByRole('combobox',{name:'Leader',exact:true}).selectOption(String(fixture.student_member));
    await page.getByRole('combobox',{name:'Status',exact:true}).selectOption('active');
    await page.getByRole('button',{name:'Save group',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Browser academic group',exact:true})).toBeVisible();
    expect((await page.goto('/projects/create')).status()).toBe(200);
    await page.getByRole('combobox',{name:'Workspace',exact:true}).selectOption(String(fixture.institution));
    await page.getByRole('combobox',{name:'Academic group',exact:true}).selectOption({index:1});
    for(const [label,value] of Object.entries({'Project title':'Browser institutional project','Description':'Collaborative prototype','Problem statement':'Research challenge','Objectives':'Validate measurements','Expected outcomes':'A working prototype','Category':'Engineering'})) {
        await page.getByLabel(label,{exact:true}).fill(value);
    }
    await page.getByLabel('I confirm the selected ownership').check();
    await page.getByRole('button',{name:'Save project',exact:true}).click();
    await expect(page.getByText('Institution-owned project',{exact:false})).toBeVisible();
    await page.getByRole('link',{name:'Team',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Current participants'})).toBeVisible();
    await expect(page.getByText('Browser Student',{exact:true})).toBeVisible();
    await page.goto('/institutions/'+fixture.institution+'/workspace');
    await expect(page.locator('[data-chart-ready=true]')).toHaveCount(9);
    expect(errors).toEqual([]);
});

