# Phase 00.3 — interactive website and contact email

Product metadata remains centralised in config/ideyra.php; version remains 1.0.0. Earlier phase reports are historical. This phase supersedes their statements that no contact Mailable exists.

## Experience delivered

The homepage has a finite animated hero, five-stage Imagine → Build → Collaborate → Discover → Achieve journey, interactive feature showcase, collaboration composition, personal/institution pathways and a revealed final CTA. The old three-step “how it works” block was replaced rather than duplicated.

The journey advances a connected card composition as its chapters cross the viewport. The showcase covers Idea Vault, Project Studio, Collaboration, Opportunities Hub, CV Builder and Portfolio. Every sample is explicitly illustrative and planned; these are website interactions, not implemented workspace tools.

About adds a connected problem/vision/solution/future narrative. Features reuses the showcase and staggered roadmap cards. Students receives progressive card reveals in its existing six-step journey. Institutions adds a contextual organisation diagram. Contact uses restrained heading/aside entrances, pending-submit feedback and explicit offline handling without animating the form fields.

The official icon/wordmark, Montserrat, central palette (including amber), existing footer/version, authentication and PWA privacy boundaries remain.

## Animation decisions and lifecycle

Evaluated GSAP/ScrollTrigger, Motion One, native Web Animations and IntersectionObserver against the existing server-rendered Blade site. Native APIs fit discrete story stages, finite hero/reveal sequences and selectable previews without another framework. No dependencies were added.

resources/js/experience.js is dynamically imported only on public pages. Its production chunk is approximately 5.84 KB / 2.21 KB gzip in the initial phase build; later builds may differ. It uses:
- IntersectionObserver for one-time reveals and story-stage transitions.
- Web Animations for finite transform/opacity sequences, with small stagger delays.
- CSS transitions for the connected story composition.
- AbortController, observer disconnects, animation cancellation and restoration functions for teardown.
- Livewire navigating/navigated events, scoped child-list observation for inserted/replaced content and pagehide/pageshow handling.

No scroll listener, continuous animation loop, scroll interception, external media dependency or overlapping animation library was introduced. Content has no default hidden animation state. Unsupported or failed enhancements leave the ordinary document readable.

The showcase starts as six visible articles and anchor links. JavaScript upgrades it to labelled tabs/panels with roving focus, arrow keys, Home/End and touch/click selection. Cleanup restores its readable unenhanced state. DOM replacement and lifecycle behavior are browser tested.

## Mobile and accessibility

Desktop storytelling uses a bounded sticky illustration beside normal document-flow chapters. At 800px and below it becomes a single-column sequence; the redundant decorative scene is omitted and the chapters remain fully readable. It never pins or traps scrolling. Feature controls use a grid on small screens with at least 44px targets. Collaboration and institution layouts stack rather than overflow.

Reduced-motion preference prevents native animation playback, stops existing animation instances and removes the enhanced moving scene. No essential action depends on hover. Existing navigation, focus states, semantic labels and reduced-motion CSS remain. Safe-area insets are accounted for in the public header.

Offline contact handling leaves entered text on the current page and explains that the user must reconnect and submit manually. No message is stored in browser storage, replayed or queued. Ordinary non-JavaScript form submission still works online.

## Contact mail architecture

The inspected local default was MAIL_MAILER=log and remains unchanged. The existing .env, credentials and database connection were not edited.

Recipient:
- IDEYRA_CONTACT_EMAIL, read only in config/ideyra.php.
- Default: akuzwe0@gmail.com.
- Controllers/services use config('ideyra.contact_email').
- Recipient is independent of MAIL_FROM_ADDRESS / MAIL_FROM_NAME.
- .env.example now documents the recipient setting.

ContactController validates and persists the encrypted enquiry first. DeliverContactEnquiry then prepares a dedicated ContactEnquiryMail and uses the configured mailer. The Mailable has professional HTML, plain-text fallback, configured sender, visitor Reply-To and a safe subject. HTML visitor content is escaped; raw plain text is used only in the text MIME part. No visitor address is used as the sender.

Existing CSRF protection, consent, length validation, honeypot and rate limits remain. Storage failure and mail failure are handled separately so a transport error does not discard an enquiry or encourage duplicate submissions. Failures log only the enquiry identifier and exception class, not raw provider responses or credentials.

Sending is synchronous in this low-volume phase; no queue worker is required. SMTP now has a configurable MAIL_TIMEOUT (default 15 seconds). Reliable background delivery, controlled retry operations and provider webhooks can be added later; no automatic retry is currently performed.

## Delivery tracking and truthful feedback

The additive migration adds:
- mail_status (existing records start as not_attempted).
- Encrypted mail_recipient.
- mail_mailer.
- mail_attempted_at.
- mail_accepted_at.

Statuses:
- not_attempted — older enquiry; no new attempt made.
- pending — attempt started; a process interruption can leave this state.
- logged — local log transport recorded the message; no inbox delivery.
- simulated — array transport; no inbox delivery.
- accepted — a direct external transport returned acceptance; inbox arrival still unverified.
- unconfirmed — transport result is ambiguous, null, or uses failover/roundrobin.
- failed — preparation/transport failed; the enquiry remains stored.

Composite transports stay unconfirmed because a log fallback must never be labelled as external delivery. The public confirmation is based on the outcome, never a blanket “email delivered” message. A database error after transport acceptance can also leave an uncertain state; operational reconciliation is required rather than blindly resending.

The log mailer writes message content to local logs, which must remain outside the web root with restricted access and an appropriate retention policy. Enquiry personal fields remain encrypted in the database. Preserve the Laravel application key for decryption. There is still no public enquiry list or administrative review UI.

No actual Gmail inbox delivery was attempted or verified. No SMTP/API provider or credentials were invented.

## Migrating to an official email address later

The following addresses are illustrations only, not claims of ownership or verification: hello@ideyra.com as a future sender/recipient.

1. Obtain control of the chosen domain and select a real mail provider. Verify the sender/domain through that provider.
2. Publish the provider's required DNS records. Configure SPF, DKIM and an appropriate DMARC policy, then confirm authentication/alignment using real test messages. Follow the provider's current instructions.
3. Set deployment configuration without changing controllers/views:
   - IDEYRA_CONTACT_EMAIL=the chosen recipient (it may remain the current Gmail address).
   - MAIL_FROM_ADDRESS=the verified sender.
   - MAIL_FROM_NAME=Ideyra.
   - MAIL_MAILER=smtp or the selected supported transactional driver.
   - SMTP settings: MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD, MAIL_TIMEOUT as supplied by the provider. API transports may require their official SDK/driver and config/services.php credentials.
4. Keep credentials in the deployment secret store or private environment configuration. Do not commit them. Do not use the visitor's email as From.
5. Clear/rebuild Laravel's configuration cache after environment changes. Restart workers if queued mail is introduced later. The current contact flow is synchronous.
6. Run the fake-mail tests and production build, then submit a deliberately labelled test enquiry in the deployed environment to a controlled inbox. Check encrypted persistence, tracked status, sender, Reply-To, HTML/text parts, provider events, spam placement and actual arrival. “Accepted” alone is not proof of inbox delivery.
7. Exercise provider failure and verify the enquiry survives without a false success message. Add authenticated delivery/bounce webhook processing before claiming confirmed delivery.

Laravel mail reference: https://laravel.com/framework/docs/13.x/mail.

## Files

Added:
- app/Mail/ContactEnquiryMail.php.
- app/Support/DeliverContactEnquiry.php.
- database/migrations/2026_10_23_123153_add_mail_tracking_to_enquiries_table.php.
- resources/views/mail/contact-enquiry.blade.php and contact-enquiry-text.blade.php.
- resources/js/experience.js and resources/css/experience.css.
- resources/views/components/marketing/{journey,showcase,collaboration,narrative,institution-map}.blade.php.
- tests/Feature/ContactMailTest.php and tests/Browser/experience.spec.js.

Updated:
- ContactController and Enquiry.
- config/ideyra.php, config/mail.php and .env.example.
- CSS/JS entrypoints.
- Home, About, Features, Institutions and Contact views.
- Existing contact and browser tests.
- The Students view is reused unchanged; native enhancement targets its existing semantic journey cards.

The mail-tracking migration was applied successfully; no original table or data was reset. No dependency manifests changed.

## Verification

- php artisan migrate --no-interaction: additive mail-tracking migration applied.
- php artisan test --compact: 34 passed, 367 assertions.
- php vendor/bin/pint --dirty --format agent: run and passed after formatting.
- npm.cmd run build: passed.
- php artisan route:list --except-vendor: existing 19 application patterns retained.
- Configuration inspection confirmed ideyra.contact_email = akuzwe0@gmail.com and mail.default = log.
- git diff --check: passed.
- Chrome: all 8 scenarios passed (2.4 minutes). Checks cover approximately 360px, 390px, 768px and 1440px, scroll-stage progression, all six preview selections, keyboard controls, reduced motion, reading/navigation without JavaScript, teardown/reinitialisation, offline form handling, authentication, contact persistence and PWA fallback.

Screenshots: storage/app/phase003-{1440,768,390,360}-{journey,showcase}.png plus the existing public-page screenshots. Browser testing uses a separate SQLite database and array mailer, so it neither changes real MySQL enquiry/account data nor contacts a real inbox. PHPUnit uses in-memory SQLite.

Both requested references were inspected in headless Chrome at desktop and mobile sizes. Broad inspiration came from their content hierarchy and staged storytelling. No reference source, copy, illustrations or distinctive layout was reused.

## Remaining work and next phase

No live inbox delivery is available while the log mailer is active. Provider setup and actual inbox tests require genuine provider/domain configuration. Administrative enquiry review, retention policy, delivery webhooks/retries, physical Android performance testing, a screen-reader audit, device installation and multi-tab PWA-update testing remain future work.

Next recommended phase: complete Authentication and Identity (recovery, verification and account lifecycle), then implement actual workspace modules behind explicit ownership and membership rules. The new feature previews remain illustrations.
