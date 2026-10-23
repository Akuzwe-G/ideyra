@if($layout === 'auth')
<x-layouts.auth><div class="auth-panel"><x-ui.badge>Phase 00 · Layout preview</x-ui.badge><h1>Welcome to your next chapter.</h1><p>A focused space for account access, coming in Phase 01.</p><x-ui.alert>Registration, sign-in and password recovery are not available yet.</x-ui.alert><x-ui.button :href="route('home')" variant="secondary">Return to Ideyra</x-ui.button></div></x-layouts.auth>
@else
<x-layouts.workspace :institution="$layout === 'institution'">
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('home') }}">Ideyra</a><span aria-hidden="true">/</span><span aria-current="page">Foundation preview</span></nav>
<x-ui.heading :title="$layout === 'components' ? 'A consistent starting point.' : ($layout === 'institution' ? 'A space for shared ambition.' : 'Make room for your next idea.')" eyebrow="Phase 00 / Foundation">A preview of the environment we’re building. All modules shown here are planned.</x-ui.heading>
<x-ui.alert>Development preview. No authentication, memberships or workspace features are implemented.</x-ui.alert>
@if($layout === 'components')
<div class="component-grid">
<x-ui.card><h2>Actions & status</h2><div class="button-row"><x-ui.button data-open-dialog="confirmation">Open confirmation</x-ui.button><x-ui.button disabled>Unavailable</x-ui.button></div><p><x-ui.badge>Planned</x-ui.badge></p><x-ui.alert :error="true">Example error: the request could not be completed.</x-ui.alert><p role="status"><span class="loading-dot" aria-hidden="true"></span> Loading example…</p></x-ui.card>
<x-ui.card><h2>Fields & feedback</h2><x-ui.input name="example-title" label="Idea title" hint="A short, memorable name." placeholder="Your next idea" /><x-ui.input name="example-error" label="Validation example" error="Please enter a title." /></x-ui.card>
<x-ui.card><h2>Empty state</h2><x-ui.empty title="A blank page, full of possibility.">Future ideas will appear here. This preview does not save data.</x-ui.empty></x-ui.card>
<x-ui.card><h2>Tables & pagination</h2><div class="table-scroll" role="region" aria-label="Module roadmap" tabindex="0"><table><caption>Illustrative roadmap</caption><thead><tr><th scope="col">Module</th><th scope="col">Status</th></tr></thead><tbody><tr><th scope="row">Identity</th><td><x-ui.badge>Phase 01</x-ui.badge></td></tr><tr><th scope="row">Idea Vault</th><td>Planned</td></tr></tbody></table></div><nav class="pagination" aria-label="Pagination example"><span aria-disabled="true">Previous</span><span aria-current="page">1</span><span aria-disabled="true">Next</span></nav></x-ui.card>
</div><x-ui.dialog id="confirmation" title="Confirm an action" :destructive="true"><p>Destructive actions must explain their impact and require explicit confirmation. This example changes no data.</p></x-ui.dialog>
@else
<div class="component-grid"><x-ui.card><p class="eyebrow">Built around you</p><h2>{{ $layout === 'institution' ? 'An institution, many possibilities.' : 'From a spark to something real.' }}</h2><p>{{ $layout === 'institution' ? 'Future programmes, classes and supervised projects will live within an explicit institution context.' : 'Your independent workspace will bring ideas, projects and opportunities together. Personal content will be private by default.' }}</p><x-ui.badge>Planned environment</x-ui.badge></x-ui.card><x-ui.card><x-ui.empty title="The foundation is taking shape.">We’re creating the structure before adding your content and collaboration tools.</x-ui.empty></x-ui.card></div>
@endif
</x-layouts.workspace>
@endif
