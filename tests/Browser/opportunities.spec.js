import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const fixture=()=>JSON.parse(process.env.IDEYRA_BROWSER_FIXTURES);
async function login(page,email) {
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill(email);
    await page.getByLabel('Password',{exact:true}).fill(process.env.IDEYRA_BROWSER_PASSWORD);
    await page.getByRole('button',{name:'Log In',exact:true}).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
async function logout(page) {
    await page.locator('[data-profile-menu]>summary').click();
    await page.getByRole('button',{name:'Log out',exact:true}).click();
    await expect(page).toHaveURL(/\/$/);
}
async function confirm(page) {await page.getByRole('dialog').getByRole('button',{name:'Confirm change',exact:true}).click();}
async function publication(page,status) {
    await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption(status);
    await page.getByRole('textbox',{name:'Decision reason',exact:true}).fill('Authorised publication test decision.');
    await page.getByRole('button',{name:'Record publication decision',exact:true}).click();
    await confirm(page);
    await expect(page.locator('.page-toolbar .status-badge').first()).toHaveText(new RegExp(status,'i'));
}
async function uploadSample(page) {
    await page.getByRole('combobox',{name:'Document purpose',exact:true}).selectOption({index:1});
    await page.getByLabel('Document (up to 10 MB)',{exact:true}).setInputFiles({name:'research-sample.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF')});
    await page.getByRole('checkbox',{name:/I am authorised to share this document/}).check();
    await page.getByRole('button',{name:'Upload private document',exact:true}).click();
    await expect(page.getByText('Private supporting document uploaded.',{exact:true})).toBeVisible();
}
async function submitApplication(page) {
    await page.getByRole('checkbox',{name:/I explicitly authorise sharing this application/}).check();
    await page.getByRole('button',{name:'Submit application',exact:true}).click();
    await confirm(page);
    await expect(page.locator('.page-toolbar .status-badge')).toHaveText(/Submitted/);
}
function captureErrors(page) {
    const errors=[];page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});return errors;
}
test('platform publication, student document application, private review and scoped analytics work end to end',async({page})=>{
    test.setTimeout(240000);
    const errors=captureErrors(page);
    await login(page,'system@browser.example.test');
    expect((await page.goto('/opportunities/create')).status()).toBe(200);
    await page.getByLabel('Opportunity title',{exact:true}).fill('Browser research fellowship');
    await page.getByRole('combobox',{name:'Category',exact:true}).selectOption({index:1});
    for(const [name,value] of Object.entries({'Short summary':'A fixture programme for student research.','Full description':'Develop a supervised research prototype with an academic team.','Application instructions':'Provide a motivation and relevant work sample.','Eligibility requirements explained':'Active student membership and informed group consent when applying as a group.','Organising institution or organisation':'Browser Organiser','Source attribution':'Browser organiser test announcement'})){
        await page.getByRole('textbox',{name,exact:true}).fill(value);
    }
    await page.getByRole('checkbox',{name:/Accept academic group applications/}).check();
    await page.getByRole('textbox',{name:'Question label or document purpose',exact:true}).nth(0).fill('Why this programme?');
    await page.getByRole('checkbox',{name:'Required response',exact:true}).nth(0).check();
    await page.getByRole('textbox',{name:'Question label or document purpose',exact:true}).nth(1).fill('Relevant work sample purpose');
    await page.getByRole('combobox',{name:'Supported field type',exact:true}).nth(1).selectOption('document');
    await page.getByRole('checkbox',{name:'Required response',exact:true}).nth(1).check();
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    await expect(page.getByText('This is an authorised preview.',{exact:false})).toBeVisible();
    const opportunityUrl=page.url();
    for(const status of ['submitted','approved','published']) await publication(page,status);
    await logout(page);
    await login(page,'student@browser.example.test');
    await page.goto('/opportunities');
    await page.getByRole('link',{name:'Browser research fellowship',exact:true}).click();
    await page.getByRole('button',{name:'Save opportunity',exact:true}).click();
    await page.getByRole('link',{name:/Prepare application/}).click();
    await page.getByRole('textbox',{name:'Personal or group motivation',exact:true}).fill('I want to develop a water research prototype.');
    await page.getByRole('textbox',{name:'Why this programme?',exact:true}).fill('It aligns with my research goals.');
    await page.getByRole('button',{name:'Save application draft',exact:true}).click();
    const applicationUrl=page.url();
    await uploadSample(page);
    await submitApplication(page);
    await logout(page);
    await login(page,'system@browser.example.test');
    await page.goto(applicationUrl);
    await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption('more_information_requested');
    await page.getByRole('textbox',{name:'Applicant-visible feedback',exact:true}).fill('Please clarify your planned contribution.');
    await page.getByRole('textbox',{name:'Confidential internal note (optional)',exact:true}).fill('Confidential browser reviewer note.');
    await page.getByRole('button',{name:'Record review decision',exact:true}).click();await confirm(page);
    await expect(page.getByText('Application action recorded.',{exact:true})).toBeVisible();
    await logout(page);await login(page,'student@browser.example.test');await page.goto(applicationUrl);
    await expect(page.getByText('Please clarify your planned contribution.',{exact:true})).toBeVisible();
    await expect(page.getByText('Confidential browser reviewer note.',{exact:true})).toHaveCount(0);
    await page.getByRole('link',{name:'Edit current draft',exact:true}).click();
    await page.getByRole('textbox',{name:'Personal or group motivation',exact:true}).fill('I will build and document the sensor prototype.');
    await page.getByRole('button',{name:'Save application draft',exact:true}).click();await submitApplication(page);
    await logout(page);await login(page,'system@browser.example.test');await page.goto(applicationUrl);
    await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption('accepted');
    await page.getByRole('textbox',{name:'Applicant-visible feedback',exact:true}).fill('Your contribution is accepted.');
    await page.getByRole('button',{name:'Record review decision',exact:true}).click();await confirm(page);
    await expect(page.locator('.page-toolbar .status-badge')).toHaveText(/Accepted/);
    await page.goto('/opportunity-analytics?scope=system');
    await expect(page.locator('[data-chart-ready=true]')).toHaveCount(4);
    const chart=page.locator('[data-chart]').filter({has:page.getByRole('heading',{name:'Opportunity application status',exact:true})});
    const csvPromise=page.waitForEvent('download');await chart.getByRole('link',{name:/Export CSV/}).click();expect(readFileSync(await (await csvPromise).path(),'utf8')).toContain('Accepted,1');
    await page.getByRole('button',{name:'Switch to dark mode'}).click();await expect(page.locator('.workspace')).toHaveAttribute('data-dashboard-theme','dark');
    for(const width of [1440,768,390,360]){
        await page.setViewportSize({width,height:900});await page.goto('/opportunities');
        await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await page.screenshot({path:'storage/app/phase03-hub-'+width+'.png',fullPage:true,animations:'disabled'});
    }
    await page.goto(opportunityUrl);await expect(page.getByRole('heading',{name:'Browser research fellowship',exact:true})).toBeVisible();
    expect(errors).toEqual([]);
});
test('group leader obtains each participating student’s informed consent before submitting',async({page})=>{
    test.setTimeout(180000);const errors=captureErrors(page);const f=fixture();
    await login(page,'admin@browser.example.test');
    await page.goto('/institutions/'+f.institution+'/members/'+f.second_student_member);
    await page.getByLabel('Membership status',{exact:true}).selectOption('active');
    await page.getByRole('button',{name:'Confirm membership changes',exact:true}).click();await confirm(page);
    await expect(page.getByText('Membership saved. Global identity details were not changed.',{exact:true})).toBeVisible();
    await page.goto('/groups/create?institution_id='+f.institution);
    await page.getByLabel('Group name',{exact:true}).fill('Browser consent group');
    await page.getByRole('combobox',{name:'Academic class',exact:true}).selectOption(String(f.class));
    await page.getByRole('checkbox',{name:'Browser Student',exact:true}).check();await page.getByRole('checkbox',{name:'Browser Group Participant',exact:true}).check();
    await page.getByRole('combobox',{name:'Leader',exact:true}).selectOption(String(f.student_member));
    await page.getByRole('combobox',{name:'Status',exact:true}).selectOption('active');
    await page.getByRole('button',{name:'Save group',exact:true}).click();await expect(page.getByRole('heading',{name:'Browser consent group',exact:true})).toBeVisible();
    await logout(page);await login(page,'student@browser.example.test');await page.goto('/opportunities');
    await page.getByRole('link',{name:'Browser research fellowship',exact:true}).click();await page.getByRole('link',{name:/Prepare application/}).click();
    const groupSelect=page.getByRole('combobox',{name:'Apply as',exact:true});
    const groupValue=await groupSelect.locator('option').filter({hasText:'Browser consent group'}).getAttribute('value');await groupSelect.selectOption(groupValue);
    await page.getByRole('checkbox',{name:'Browser Group Participant',exact:true}).check();
    await page.getByRole('textbox',{name:'Personal or group motivation',exact:true}).fill('Our group will build the research prototype together.');
    await page.getByRole('textbox',{name:'Why this programme?',exact:true}).fill('We have complementary research skills.');
    await page.getByRole('button',{name:'Save application draft',exact:true}).click();const url=page.url();await uploadSample(page);
    await expect(page.getByText('Browser Group Participant · Consent pending',{exact:true})).toBeVisible();
    await logout(page);await login(page,'participant@browser.example.test');await page.goto(url);
    await page.getByRole('combobox',{name:'My consent',exact:true}).selectOption('1');
    await page.getByRole('button',{name:'Record my choice',exact:true}).click();
    await expect(page.getByText('Browser Group Participant · Consent recorded',{exact:true})).toBeVisible();
    await logout(page);await login(page,'student@browser.example.test');await page.goto(url);await submitApplication(page);
    await page.getByText(/^Version 1 ·/).click();await expect(page.getByText('Participants: Browser Student, Browser Group Participant',{exact:true})).toBeVisible();
    expect(errors).toEqual([]);
});
test('personal project support preserves privacy and requires staff verification to fulfil commitments',async({page})=>{
    test.setTimeout(180000);const errors=captureErrors(page);
    await login(page,'student@browser.example.test');await page.goto('/projects/create');
    for(const [name,value] of Object.entries({'Project title':'Browser supported project','Description':'A student-owned prototype.','Problem statement':'Water monitoring challenge.','Objectives':'Validate sensor measurements.','Expected outcomes':'A reliable demonstrator.','Category':'Engineering'})){await page.getByRole('textbox',{name,exact:true}).fill(value);}
    await page.getByRole('checkbox',{name:/I confirm the selected ownership/}).check();await page.getByRole('button',{name:'Save project',exact:true}).click();
    const projectUrl=page.url();await page.getByRole('link',{name:'Team',exact:true}).click();
    await page.getByLabel('Exact account email',{exact:true}).fill('reviewer@browser.example.test');
    await page.getByRole('combobox',{name:'Project role',exact:true}).selectOption('supervisor');await page.getByRole('button',{name:'Save assignment',exact:true}).click();
    await page.goto(projectUrl);await page.getByRole('link',{name:'Request support',exact:true}).click();
    await page.getByRole('combobox',{name:'Support type',exact:true}).selectOption('equipment');
    for(const [name,value] of Object.entries({'Describe the requested support':'Two water quality sensors.','Why this support is needed':'The prototype requires reliable measurement hardware.','Intended use':'Measure water quality during the research project.'})){await page.getByRole('textbox',{name,exact:true}).fill(value);}
    await page.getByRole('button',{name:'Save support request draft',exact:true}).click();const requestUrl=page.url();
    await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption('pending_review');await page.getByRole('textbox',{name:'Reason',exact:true}).fill('Independent review requested.');await page.getByRole('button',{name:'Record request decision',exact:true}).click();await confirm(page);
    await expect(page.locator('.page-toolbar .status-badge')).toHaveText(/Pending review/);
    await logout(page);await login(page,'reviewer@browser.example.test');await page.goto(requestUrl);
    await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption('approved_outreach');await page.getByRole('textbox',{name:'Reason',exact:true}).fill('Approved for non-payment support outreach.');await page.getByRole('button',{name:'Record request decision',exact:true}).click();await confirm(page);
    await expect(page.locator('.page-toolbar .status-badge')).toHaveText(/Approved outreach/);
    await page.getByLabel('Sponsor organisation or individual name',{exact:true}).fill('Confidential browser sponsor');
    await page.getByLabel('Contact email',{exact:true}).fill('sponsor@browser.example.test');await page.getByRole('checkbox',{name:/I am authorised to record these contact details/}).check();await page.getByRole('button',{name:'Record contact',exact:true}).click();
    await page.getByText('Record contact verification',{exact:true}).click();await page.getByRole('textbox',{name:'Verification basis / evidence',exact:true}).fill('Institution staff confirmed the sponsor contact.');
    await page.getByRole('button',{name:'Verify contact',exact:true}).click();await expect(page.getByText('Sponsor verification recorded.',{exact:true})).toBeVisible();
    await page.getByRole('textbox',{name:'Offered support',exact:true}).fill('Two sensors for the student prototype.');await page.getByRole('button',{name:'Record proposed commitment',exact:true}).click();
    for(const status of ['under_discussion','committed','fulfilled']){
        await page.getByText('Update commitment',{exact:true}).click();await page.getByRole('combobox',{name:'Next status',exact:true}).selectOption(status);
        await page.getByRole('textbox',{name:'Decision reason',exact:true}).fill('Authorised staff reviewed the support status.');
        if(status==='fulfilled')await page.getByRole('textbox',{name:'Fulfilment verification evidence (required for fulfilled)',exact:true}).fill('Staff checked the equipment receipt.');
        await page.getByRole('button',{name:'Record commitment status',exact:true}).click();await confirm(page);
        await expect(page.getByText('Commitment status updated. A pledge is not money received.',{exact:true})).toBeVisible();
    }
    await logout(page);await login(page,'student@browser.example.test');await page.goto(requestUrl);
    await expect(page.getByText('Confidential browser sponsor',{exact:true})).toHaveCount(0);await expect(page.getByText('sponsor@browser.example.test',{exact:true})).toHaveCount(0);
    await expect(page.getByText('Two sensors for the student prototype.',{exact:true})).toBeVisible();await expect(page.getByText('Fulfilled',{exact:true})).toBeVisible();
    expect(errors).toEqual([]);
});

