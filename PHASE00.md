# Ideyra — Phase 00 application foundation

Ideyra is a planned personal innovation, collaboration and career development platform, with independent personal workspaces and multiple institutional contexts. This deliverable supplies the public site and technical/UI foundation. Identity, memberships and all workspace modules remain unimplemented.

## Stack and dependencies

PHP 8.3+, Laravel 13.38, MySQL, Blade, Livewire 4.4.8, Tailwind 4.3, Vite 8.3, Node 22. Livewire is installed and its layout directives are ready; Phase 00 has no reactive business features. Laravel Boost 2.10 is a development dependency required by AGENTS.md. @fontsource/montserrat supplies local font files; no third-party font requests. @playwright/test provides repeatable Chrome browser checks. Lockfiles pin resolved versions.

## Local setup

Preserve the existing .env and database. On a new checkout only, copy .env.example to .env, configure your own MySQL connection and generate an application key. Never publish secrets.

Run composer install and npm.cmd ci. Run php artisan migrate only to apply reviewed outstanding migrations; Phase 00 adds none. Run npm.cmd run build, then php artisan serve (or use Laragon with public/ as the document root). For frontend development use npm.cmd run dev in another terminal. The service worker is registered only with production assets, on HTTPS or localhost. It is disabled during Vite development to avoid caching hot-reload assets.

Local preview URLs:
- / — public landing page.
- /preview/auth — authentication layout; no sign-in or registration.
- /preview/personal — personal shell.
- /preview/institution — institution shell; no membership or permissions.
- /preview/components — UI reference and working native confirmation dialog.
- /offline.html — session-free offline fallback.
- /manifest.webmanifest and /sw.js — PWA endpoints.

Preview controllers return 404 outside local/testing environments. Deploy with APP_ENV=production and APP_DEBUG=false, configure HTTPS and cache configuration through normal Laravel deployment steps. Do not expose Vite's development server or development credentials publicly.

## Brand and version

config/ideyra.php is the source of product name, tagline, version, copyright year, palette and official asset paths. Product version is independent of Laravel. Base layout maps palette values into CSS custom properties. resources/css/app.css maps these to semantic surface, canvas, text, muted, action and line tokens. A future dark theme should override semantic tokens under [data-theme="dark"] and add a user preference; components need no palette rewrite.

Montserrat 400/500/600/700 is self-hosted. Palette: navy #132447, innovation blue #3759D7, teal #16B8C6, sky #EEF3FF, white #FFFFFF, slate #62718A. Teal is an accent rather than small text on white. A semantic danger token supports destructive/error states.

Official assets were absent. Bracketed text explicitly identifies pending brand assets; it is not a generated substitute logo. The base layout suppresses the starter favicon.

Supply trusted official files under public/brand/:
- text.svg — expanded wordmark.
- icon.svg — compact icon and favicon.
- icon-192.png — actual 192 × 192 PNG.
- icon-512.png — actual 512 × 512 PNG.

SVG paths can be changed in config/ideyra.php. The manifest includes PNG references only when files exist, so it does not advertise broken assets. Supply correctly sized files, rebuild/redeploy and clear cached config if asset paths change. Optional maskable icons require separately approved artwork with the correct safe area; do not relabel arbitrary icons as maskable. Installability remains incomplete until the official PNG icons are supplied and tested.

## Layout and UI conventions

resources/views/components/layouts/base.blade.php owns document metadata, font/assets, skip link, Livewire hooks and update notices. Public, auth and workspace layouts compose it; workspace has an explicit institution parameter. Views contain no queries or fake account state. Institution and personal previews load no user data.

resources/views/components/ui provides buttons/links, cards, badges, alerts, labelled inputs with feedback, page headings, empty states and native dialogs. The UI reference demonstrates semantic tables in scroll regions, pagination, loading/error states, breadcrumbs, navigation and destructive confirmation. Use actual paginator links when real list data exists; the example deliberately has no fake pagination actions. Dialog buttons return values only; future business actions must handle a confirmed result through authorised, CSRF-protected server requests. Disabled actions explain availability. Native focus outlines, reduced-motion support, clear labels, landmarks and keyboard dialog dismissal are retained.

## PWA architecture and privacy

The evaluated vite-plugin-pwa approach offers Workbox-generated or injected service workers (https://vite-pwa-org.netlify.app/guide/). For this small server-rendered scope, a native worker avoids a separate build plugin and broad runtime-cache defaults. Livewire installation guidance: https://livewire.laravel.com/docs/4.x/installation.

routes/pwa.php is registered separately in bootstrap/app.php without session-based web middleware. These endpoints support GET/HEAD only, render public metadata/static output and emit no session or CSRF values. Normal application routes keep Laravel's web middleware, request-forgery protection and sessions.

FoundationController reads Vite's production manifest to enumerate exact build assets, including fonts. A revision hash covers the asset list, worker template, offline template and product configuration. Every deployment must build frontend assets before serving the app; changes to CSS, fonts or JavaScript change their hashed filenames and therefore the worker revision.

Installation precaches the offline HTML plus the exact static asset allowlist. Navigations are network-only with the public offline fallback on transport failure; live HTML is never written to Cache Storage. HTTP 4xx/5xx responses remain visible rather than being disguised as offline success. Same-origin GET requests matching exact static build URLs use the current static cache; API, login, reset, profile, session, query-string and mutation requests are never added. No offline replay/background sync exists. Logout/account switching cannot reveal personal HTML from this cache because none is stored.

A newly installed worker waits until the user chooses Update now or all older clients close. Later dismisses the notice. Choosing Update now explicitly activates the waiting worker and reloads the requesting tab. Activation removes only previous ideyra-static-* caches. Preserve old hashed deployment assets while older tabs may still use them. This is a root-scope application: subdirectory hosting requires coordinated asset, route, worker-scope and manifest changes.

Only browser-supported installation prompts are shown. There is no fake install button, push notification, private content persistence, offline editing or synchronisation. Offline fallback supplies meaningful guidance and a reconnect link; it is a public shell, not a cached functional workspace. First-time offline access before a successful online worker installation cannot work.

## Architecture and roadmap

Use Laravel controllers, policies, form requests, Eloquent and Livewire components by feature. Do not add speculative schema or repository layers.

- Authentication and identity (Phase 01): registration, login, recovery, verification, session lifecycle and identity-level authorisation.
- Platform administration: separate global privileges from institution roles.
- Institutions and memberships: users can join multiple institutions; membership grants access only within its institution.
- Personal workspace and Idea Vault: content belongs to its owner, private by default, access only through explicit sharing.
- Project Studio and collaboration: explicit ownership/context and collaborator grants; membership alone must not expose personal content.
- Opportunities and professional profiles: separate public/published projections from private drafts.
- CV and portfolio generation: explicit publishing/export permissions and controlled asset access.

Future queries, routes and policies must enforce the selected institution plus current membership. A URL parameter, sidebar selection or global role is not permission. Do not implement global institution scopes that accidentally hide independent personal content. Establish real ownership and policy tests before exposing any data. Offline storage for future private modules requires a separate threat model, account isolation, logout cleanup and synchronisation design.

## Verification

- php artisan test --compact — PHPUnit suite; feature tests use no database refresh or destructive operations.
- php artisan route:list --except-vendor — public and preview route inspection.
- php artisan migrate:status --no-interaction — read-only existing schema status.
- npm.cmd run build — production assets.
- php vendor/bin/pint --format agent — formatter. --dirty is unavailable because this workspace has no Git repository.
- npx.cmd playwright test — installed Chrome; starts/reuses localhost:8010. Checks 1440px/390px layouts, browser errors, dialog Escape dismissal, actual registration, public-only cache contents, offline navigation and reconnect. Screenshots go to storage/app/phase00-*.png.

No .env changes, migrations or data mutations are part of Phase 00. Browser checks use public pages and no real accounts. Detailed run outcomes are recorded in PHASE00-RESULTS.md.

## Limitations

Official logos/install icons remain outstanding. Browser installation and OS integration require approved icons and browser-specific device testing. Update activation across multiple live tabs, screen-reader assessment and a full accessibility audit remain release checks. Identity and all product modules are future work. Do not present layout previews as implemented features. This directory was not a Git checkout at inspection.

Next deliverable after review: Phase 01 — Authentication and Identity.
