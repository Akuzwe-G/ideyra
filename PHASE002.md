# Ideyra Phase 00.2 — public website, brand and PWA refinement

Product version: read from config/ideyra.php. This document supersedes the Phase 00 notes about missing branding, unavailable account access and placeholder public navigation. Previous phase documents remain historical records.

## Delivered

Six public pages:
- / (home): value proposition, official branding, planned innovation journey, personal/institutional use cases, opportunities, calls to action.
- /about: purpose, vision, mission, audiences and product principles.
- /features: grouped, explicitly planned innovation, collaboration, opportunity, career and institutional tools.
- /for-students: six-stage student journey and personal/institutional boundaries.
- /for-institutions: academic organisation, supervision and future membership/privacy architecture.
- /contact: validated, rate-limited enquiry storage with truthful confirmation.

The public header/footer are shared. Active navigation, an accessible mobile disclosure menu, keyboard Escape dismissal, visible focus and a usable navigation fallback without JavaScript are provided. New marketing components supply page introductions, section headings, feature cards and CTA blocks. Existing foundation components and local-only previews remain.

## Authentication scope

No authentication existed before this assignment. Native Laravel session authentication now provides:
- GET/POST /register (register / register.store).
- GET/POST /login (login / login.store).
- POST /logout (logout).
- GET /dashboard (dashboard), a minimal authenticated account page, not a full workspace dashboard.

Laravel guest/auth middleware guards these routes. Passwords use the existing User model hashed cast. Registration validates unique email, password confirmation and a minimum 12-character password with upper/lowercase letters and numbers. Emails are normalised before validation. Login and registration have IP/identity rate limits. Login and registration regenerate sessions; logout invalidates the session and regenerates the CSRF token. All web HTML receives private, no-store cache headers. Private identity/preview pages receive noindex metadata.

Password recovery, email verification, profile editing, account deletion and institutional roles are not implemented. The UI explains the recovery limitation. Complete identity lifecycle work before treating the platform as production-ready for real users.

## Contact storage and operations

The contact form uses ContactRequest and CSRF-protected POST /contact. Fields: name, email, category, subject, message, consent and a hidden honeypot. Validation limits lengths/categories and requires consent; submissions are limited to five per IP per hour, including invalid attempts.

The additive create_enquiries_table migration was applied successfully to the existing configured database. No original tables/data were reset. Name, email, subject and message are encrypted with Laravel encrypted casts before storage; category and consent timestamp remain queryable. Private values are also hidden from default model serialisation. IP addresses are not stored with enquiries.

Success explicitly says the enquiry was stored for later review, no email was sent and replies are not available through the website. Storage failures return an actionable error instead of success. No outbound email, public support address or invented legal policy was added.

There is deliberately no public enquiry-list API or unfinished administrative UI. Review currently requires authorised application-level developer/operations access that decrypts through the Enquiry model; direct SQL sees ciphertext for personal fields. Preserve APP_KEY and follow Laravel key-rotation guidance when rotating keys. A later authorised admin workflow and an explicit enquiry retention/deletion policy are still needed.

## Brand assets and reproducible generation

Official source files supplied during this assignment:
- public/assets/brand/ideyra-icon.png
- public/assets/brand/ideyra-wordmark.png

Both are referenced directly in the appropriate layouts. Original source files are left unchanged. Intrinsic image dimensions and CSS maintain aspect ratios; the footer wordmark loads lazily. The icon is used in compact navigation, the journey illustration and offline fallback. No substitute brand was invented.

Run php artisan ideyra:brand-assets --no-interaction after replacing the source icon. PHP GD (already installed) is required; no image dependency was added. Generation trims transparent surrounding space for derivatives, preserves artwork proportions/transparency, and produces:
- favicon-16x16.png, favicon-32x32.png.
- favicon.ico with 16/32px PNG entries; also copied to public/favicon.ico.
- apple-touch-icon.png (180px, opaque white background).
- pwa-192x192.png, pwa-512x512.png (transparent).
- pwa-maskable-192x192.png, pwa-maskable-512x512.png (opaque white).

Maskable artwork fits in a 54% square, inside the central 80%-diameter safe circle. A pixel-level automated test verifies the safe area. Rebuild/redeploy after generating assets.

Innovation Amber (#F4A340) joins the existing central brand palette. It is used selectively in opportunity elements and visual accents with navy foregrounds, not as low-contrast body text or a replacement primary action colour. Montserrat remains self-hosted.

## SEO and environment

Shared metadata supports unique page titles/descriptions, Open Graph tags with the official wordmark as a branded image fallback, PNG/ICO favicons and Apple touch icon. Canonical and Open Graph URL origins derive from configured APP_URL. Public sitemap contains only the six marketing pages. No private profile or account data is included in SEO tags.

GET /robots.txt is now served dynamically; the old static file was removed so it cannot shadow environment-specific behavior. Non-production disallows crawling. Production allows public crawling, disallows identity/dashboard/preview paths and references the configured sitemap. Robots directives are not authorisation; auth middleware still protects account access.

Before production: set a real HTTPS APP_URL, APP_ENV=production, APP_DEBUG=false and secure session-cookie settings; configure the trusted deployment/proxy environment and operational access appropriately. The existing .env was not edited.

## PWA behavior

The manifest now references four official-icon derivatives with separate any/maskable purposes. It retains root scope/start URL, standalone display, central app name and brand colours.

The worker uses a revision derived from build assets, branding file content hashes, worker/offline templates and central product configuration. Precaching fetches bypass the HTTP cache and omit credentials. It includes only generated Vite assets, public brand PNG/ICO files and the stateless offline page.

Navigation always goes to the network and falls back to a branded offline explanation on transport failure. Public routes, contact HTML, auth responses and dashboard pages are never cached. No form/mutation is queued for replay. The offline page explains that registration/login/contact require a connection and are not automatically submitted later.

Existing explicit update prompts remain. New workers wait for acceptance or older clients to close; activation removes only older Ideyra static caches. Keep prior hashed build assets available while older tabs are open. No install prompt is shown unless the browser supplies its installation event. Native OS installation and multi-tab update acceptance remain manual release checks.

## Files and structure

Added:
- app/Console/Commands/GenerateBrandAssets.php.
- app/Http/Controllers/{AuthController,ContactController}.php.
- app/Http/Requests/{LoginRequest,RegisterRequest,ContactRequest}.php.
- app/Http/Middleware/PrivateResponse.php.
- app/Models/Enquiry.php and database/factories/EnquiryFactory.php.
- database/migrations/2026_10_23_120422_create_enquiries_table.php.
- resources/views/auth/{login,register}.blade.php and dashboard.blade.php.
- resources/views/public/{about,features,for-students,for-institutions,contact}.blade.php.
- resources/views/components/public-header.blade.php, form-errors.blade.php.
- resources/views/components/marketing/{intro,heading,feature,cta}.blade.php.
- resources/views/pwa/sitemap.blade.php.
- resources/css/foundation.css (prior Phase 00 rules preserved), marketing.css.
- Official/generated public/assets/brand/*.
- tests/Feature/{AuthenticationTest,ContactTest,PublicWebsiteTest}.php.

Updated:
- config/ideyra.php, bootstrap/app.php, app/Providers/AppServiceProvider.php.
- FoundationController, routes/web.php and routes/pwa.php.
- Shared base/public/auth/workspace/brand/footer layouts/components, home and preview pages.
- Offline and worker templates, root favicon.
- CSS entry point and resources/js/app.js.
- Existing foundation feature/browser tests and playwright.config.js.

Removed public/robots.txt in favour of its dynamic stateless route. No Composer or npm dependency changes were necessary.

## Verification and local preview

Commands:
- php artisan ideyra:brand-assets --no-interaction.
- php artisan migrate --no-interaction; php artisan migrate:status --no-interaction.
- php artisan test --compact.
- php vendor/bin/pint --dirty --format agent.
- php artisan route:list --except-vendor.
- npm.cmd run build.
- npx.cmd playwright test.
- git diff --check.

Laravel: 26 tests passed, 330 assertions, covering all public pages, metadata/navigation, official/generated assets, maskable safety, PWA endpoints, development preview boundaries, registration/login/logout, guest/auth access, password hashing, invalid inputs, rate limits, contact consent/honeypot validation and encrypted storage.

Chrome automation checks 1440px, 768px and 390px rendering across six public pages, both identity pages and four layout previews; no horizontal overflow, missing critical images or browser console/page errors. Navigation and keyboard mobile-menu controls are tested. The form flow verifies actual registration, login, logout, private-page redirect, no-store headers, absence of cached dashboard HTML, contact confirmation and rejection of a CSRF-less POST. Offline checks cover each added public page and private-account navigation, original branding availability and reconnect.

Browser tests create their own SQLite database under storage/app/browser-*.sqlite and a dedicated server on port 8011; no browser accounts/enquiries are written into the configured MySQL database. PHPUnit uses its existing in-memory SQLite configuration. Browser test databases contain synthetic data and can be removed when no test server is using them.

Final complete Chrome run: 4 passed (1.4 minutes). Production build, Pint, route inspection, Blade view compilation and git diff --check also passed.

Screenshots are stored as storage/app/phase002-{1440,768,390}-{home,features,contact,login}.png and phase002-offline.png.

Preview using the existing Laragon URL or php artisan serve. Public paths are listed above. Layout examples remain /preview/{auth,personal,institution,components} and return 404 outside local/testing. Browser tests use port 8011; they do not leave a public test server running.

## Remaining scope and next assignment

No missing official brand files remain. Email delivery, operational admin review, enquiry retention decisions, recovery/verification, a full accessibility audit, device-specific installation and multi-tab update acceptance remain outstanding. Do not interpret roadmap copy or illustrative journey cards as live project/opportunity tools.

Recommended next assignment: complete Authentication and Identity (recovery, verification, account lifecycle and security hardening), building on these working session flows. Then implement institutions/memberships with explicit tenant boundaries. Do not bypass personal-content privacy to add institution features.

Framework references used: https://laravel.com/framework/docs/13.x/eloquent-mutators and https://laravel.com/framework/docs/rate-limiting.
