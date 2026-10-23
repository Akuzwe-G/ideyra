# Revised Phase 01 — institution-controlled identity, academic structure and applications

This is the current development/operations guide. Phase 00–00.3 reports remain historical; their public self-registration and “no institutional features” statements are superseded. Product metadata/version remain centralised in config/ideyra.php (1.0.0).

## Delivered scope and navigation

Public self-registration GET/POST /register, its form, request class and controller action were removed. Get Started and public account CTAs now open /apply. Login offers password reset and explains administrator-issued accounts.

Public: /apply chooses Institution or Student Recommendation; /apply/institution and /apply/student provide progressively enhanced multi-step forms, progress, field validation, conditional questions, review and consent. Without JavaScript all sections remain readable/submittable and server validation still applies. No account/institution/membership is created by application submission.

Authenticated: /dashboard is the current identity's private personal shell, /profile edits its name/phone/password, and /institutions/{institution}/workspace switches explicitly into an authorised active institution. No route accepts another user's identifier to open their personal workspace. Idea/project/opportunity/portfolio business tools remain future modules.

System Administration: /system, /system/institutions, /system/applications, /system/roles, /system/audit and /system/settings. Institution Administration: institution profile, members, academic years/intakes/programmes/classes, assignments, scoped permissions and institution-only audit activity. Navigation only offers actions the current actor can perform; controllers enforce access independently.

## Database and installed dependency

Applied additive migration: database/migrations/2026_10_23_130340_create_institution_identity_foundation.php.

- users: status and optional phone. Existing identities retain active status and existing credentials/data.
- join_applications: ULID, unique reference, type/country, encrypted responses and review history, blind search tokens, unique submission/duplicate fingerprints, review and notification metadata.
- institutions: UUID identifier, unique slug, profile/location/contact fields, private logo path, population, status, profile locks, unique originating application and creator/activation metadata.
- roles, permissions, user_roles: protected system baseline and institutional role templates/capability catalogue.
- memberships: unique institution/user, institution-local identifier, role, status, creator.
- membership_permissions: explicit allow/deny overrides per membership/capability.
- identity_invitations: random identifier, hashed single-use token, expiry, acceptance and mail status.
- academic_years, programmes, intakes, academic_classes and class_assignments.
- audit_entries: actor/context/action/subject and encrypted metadata.
- platform_settings: allowlisted application-intake setting only.

Academic years use supplied start/end dates rather than a hardcoded calendar. Programme codes, intake/year/class names and student/staff identifiers have appropriate institution-local uniqueness. Composite foreign keys include institution_id for academic references and class/member assignments, in addition to request validation. Tests confirm cross-institution SQL writes fail.

No database reset, destructive development migration or existing-account elevation occurred. Existing .env/connection/credentials remain unchanged. Tests use isolated databases.

Installed barryvdh/laravel-dompdf 3.1.2 with compatible Dompdf dependencies; Composer resolved Laravel 13 support and reported no security advisories. No role/UI/animation dependency was added.

## Role seeder and initial System Administrator

Run php artisan db:seed --class=RolePermissionSeeder --no-interaction. The dedicated seeder is idempotent and creates definitions/templates only. It creates no default account or password and never elevates the first user. It uses firstOrCreate so reruns do not silently broaden an edited role template.

Top-level name: System Administrator. Other seeded roles: Institution Administrator, Lecturer / Teacher, Mentor / Supervisor and Student. Class Representative and Group Leader are class-context assignments, not global privileged roles.

To authorise an existing active identity, a trusted operator must first verify that person and the appointment out of band, then run:

    php artisan ideyra:system-administrator authorised@example.test --confirm-email=authorised@example.test

The address above is an example, not a configured administrator. Use the explicitly authorised existing identity. Matching confirmation is mandatory. Repeating the command does not duplicate the role or assignment audit. No password is printed, changed or emailed.

If no suitable identity exists, an authorised host operator can provision one without granting a role:

    php artisan ideyra:provision-identity authorised@example.test --name="Authorised Full Name" --verified-email=authorised@example.test

This command requires an interactive console, prior contact verification and a strong password entered through hidden prompts, not shell arguments. It preserves existing identities. Run the separate System Administrator assignment only after explicit authorisation.

No real user was chosen/elevated during this implementation because no initial authorised identity was specified. Browser fixtures create synthetic administrators only inside guarded browser SQLite files; they are not deployment seed accounts.

Restrict all privileged Artisan commands, private storage and deployment credentials to trusted host operators. These are not web endpoints.

## Institution onboarding workflow

1. Review an application and explicitly approve it, or create a manually vetted institution directly.
2. Use Begin explicit institution onboarding to create a draft linked to the approved application. The unique originating-application constraint prevents duplicate institutions.
3. Open Administrators & members; System Administrator appoints an Institution Administrator through the invitation workflow.
4. The recipient sets their own password or logs in to their existing identity and accepts the membership.
5. System Administrator explicitly changes institution status to Active. Activation requires an active accepted Institution Administrator and an approved originating application.
6. Only then does the originating application become Onboarded. Creating a draft alone does not claim completed onboarding.

Statuses: draft, pending_activation, active, suspended, archived. Only System Administrator can create institutions or change status. Creator and activation actor/time are recorded. Suspended/archived institutions provide no ordinary member workspace access; System Administrator retains oversight.

Institution Administrators may edit permitted profile fields; System Administrator controls type/country/slug/status and can lock individual profile fields. Logos accept validated PNG/JPEG/WebP up to 2 MB, use random private-storage paths and are served through authenticated context checks with no-store/nosniff headers. They are never arbitrary public uploads.

## User creation, acceptance and account status

An authorised institution actor supplies full name, email, initial role and optional phone/local identifier. New users are created pending with a random unusable initial credential and no emailed password. Memberships are always pending until acceptance. Programme/intake/year context follows class assignments after acceptance.

Existing email identities are reused; an institutional invitation does not overwrite their global name, email, password or phone. Existing account holders must log in as the exact invited identity and accept the link before the additional membership becomes active. Accepting membership does not share personal content.

Invitations have 256-bit random tokens, hashed storage, random identifiers, signed URLs, 48-hour expiry and transactionally enforced single use. Reissuing expires prior invitations. Pending identities cannot bypass activation through password reset or login. Pending institution administrators may accept while the institution is draft; workspace access waits for explicit institution activation.

Institution actors manage institutional role/status/identifier, not another person's global profile. Global account suspension is System Administrator-only and cannot strand the last active institutional administrator. Institution Administrator appointments remain System Administrator-controlled. Role changes clear old capability overrides and contextual class assignments, requiring deliberate reassignment.

Final active Institution Administrator removal/suspension/demotion is blocked transactionally. Administrator baseline capabilities cannot be individually overridden to bypass that protection. Membership suspension affects only that institution; global identity and memberships elsewhere remain independent.

### Development activation and password reset

MAIL_MAILER=log remains unchanged. Activation bearer links are deliberately not sent through the log transport: invitation status is assistance_required and no activation token is written into the application mail log. A trusted operator can verify the intended recipient and prepare a private handoff:

    php artisan ideyra:activation-link MEMBERSHIP_ID --verified-email=verified-recipient@example.test

The command prints only a private file path under storage/app/private/activation-links; it does not print the URL/token. Hand off the file's expiring link through an appropriately verified private channel, then remove the handoff file after use. On Windows, enforce restrictive filesystem ACLs; Unix chmod is best effort and does not replace deployment access control. Never expose this directory through public/storage.

Password reset uses Laravel's broker, hashed expiring tokens and generic responses for unknown/inactive users. Pending accounts are excluded. Reset links use configured APP_URL, not an untrusted request Host. Under the local log mailer they are prepared in private password-reset-handoffs files instead of leaking bearer tokens into logs. A verified operator supplies the matching expiring link privately. Real configured mail transports use normal notifications.

A password reset expires after the broker's configured 60 minutes and is single use. Reset rotates remember tokens and clears database sessions. Profile password changes require the current password and remove other sessions. Profile changes cannot write email/status/roles. Email changes need a later explicit reverification workflow.

Configure HTTPS, trusted deployment hosts/proxies, secure session cookies, APP_ENV=production and APP_DEBUG=false before release. Redact signed/token query strings from webserver/proxy access logs; application exception flashing excludes token/submission/password fields.

## Permissions and authority boundaries

config/permissions.php is the catalogue; InstitutionAccess is the shared server-side context/capability check. There is no global institution Eloquent scope that could accidentally expose or hide independent personal content.

Institutional templates are globally defined by System Administrator, but every assignment and override is attached to one institutional membership. Custom role assignments must be a subset of the assigning actor's own effective rights. Platform roles cannot be assigned through institutional user creation. The protected System Administrator baseline is granted only by the verified console workflow.

Effective rights = inherited institutional template, plus explicit grants, minus explicit denials. The grouped permissions screen shows role, inherited state, override and current effective rights. Actors can change only capabilities they themselves possess in that same institution. Cross-institution targets and platform-capability overrides are rejected server-side. Changes are audited.

Catalogue groups:
- Administration: profile, member viewing/creation/editing, authorised role/permission assignment and institution audit.
- Academic: year/intake/programme/class management, staff/student assignment and assigned-class viewing.
- Projects: assigned viewing, creation, institutional creation, own editing, management/review/approval, sponsorship/request management and supported-project review.
- Opportunities: viewing, creating, publishing, own editing, institution management and review/publication approval.
- Platform: institutions, applications, administrator appointments, catalogue/roles, audit, limited configuration and future subscription oversight.

Future project/opportunity/sponsorship/review-work capabilities are labelled catalogue-only; no fake modules or payment endpoints were added. projects.create does not imply projects.approve or projects.sponsor. A future sponsorship action must use its own policy and cannot imply payment or financial authority.

System Administrator can create/edit institutional role templates, add future custom.* definitions and organise catalogue groups. Built-in capability keys, implementation flags and baseline roles are deliberately protected. New future modules must wire their actions to the catalogue and add policy tests; flipping a database flag does not implement a feature.

Platform settings currently expose application intake open/closed only. They cannot edit environment secrets, security credentials or payment settings.

## Academic administration

Institution Administrators and explicitly delegated actors manage academic years, programmes, intakes and classes with create/view/edit/status interfaces and pagination. Intake/class parents must belong to the same institution; class academic year must match its intake.

Active members can be assigned as students, lecturers, mentors, Class Representatives or Group Leaders. Staff assignments require appropriate staff membership roles; contextual leadership requires Student membership. A member can belong to multiple classes. Class Representative/Group Leader are class-level contexts pending a later real project-group module.

Assigned users see only their own classes unless granted class-management rights. No project supervision/submission module is fabricated. Bulk CSV import remains a documented later enhancement, as permitted by the assignment.

## Public application forms and data

Institution sections: institution information; applicant/authority/contact; innovation/project environment; needs/readiness. Student sections: student information; institution information; motivation/need. Field definitions and rules live in ApplicationSchema.

Forms cover the requested population/readiness/innovation/support/hub questions, useful capabilities, initial departments/staff, target timing/pilot interest, student programme/level/graduation and optional contacts/comments. Conditional answers require decision-maker information, hub details or discussion details where appropriate. Initial users are not asked for identity documents or transcripts.

Both forms include purpose-of-use notice, accuracy/review confirmation, consent, honeypot and submission throttling. No claim of a legally approved privacy policy was invented. A deployment-specific retention/privacy policy still needs institutional/legal review.

A session-bound random submission key makes retries idempotent. A separate normalised per-day fingerprint prevents repeated identical contact/institution/type submissions from creating duplicates. Another session cannot recover an existing application by guessing its email/name; it receives a generic assistance response, never another receipt.

Responses and internal review history are encrypted at rest. HMAC prefix tokens support authorised applicant/institution name search without plaintext name indexes; this supports prefix-word search, not arbitrary fuzzy matching. Reference lookup, type/country/institution/status filters and pagination are implemented. Preserve APP_KEY and plan key rotation carefully because it also protects encryption, blind indexes and signatures.

## Review, notifications and PDFs

Application states: submitted, under_review, more_information_requested, approved, rejected, onboarded. State transitions are checked transactionally. System Administrator sees all submitted responses, notification state, private review history and applicant-safe PDF export.

Internal notes remain encrypted/private. A separate explicitly public message is required for more-information requests and can be sent to the applicant; private notes are never automatically copied into it. Approval alone creates no institution/account. Existing draft onboarding is resumed rather than duplicated.

Recipients are independent:
- IDEYRA_APPLICATION_EMAIL defaults to akuzwe0@gmail.com.
- IDEYRA_CONTACT_EMAIL independently defaults to akuzwe0@gmail.com.
- Sender remains configured through MAIL_FROM_ADDRESS/MAIL_FROM_NAME.

ApplicationNotification includes reference/type/applicant/institution/location/date/summary and a System Administrator-authenticated review link. ApplicationUpdateMail sends only the explicitly public message. Attempts, last outcome and failures are tracked; application storage survives transport failure. Local log/array statuses do not claim Gmail inbox delivery. Real external transport acceptance is not labelled confirmed delivery. Existing contact mail tracking remains intact.

Applicant receipts/PDF routes use unpredictable ULIDs plus 72-hour Laravel signatures, private/no-store/no-referrer/noindex handling and no unauthenticated access through numeric IDs alone. PDFs include the official wordmark, reference/type/time/status, all submitted fields and consent timestamp. They exclude reviewer IDs, internal notes and assessments. Dompdf remote fetching and PHP are disabled; submitted values are escaped. Website typography remains Montserrat; print PDFs use bundled Unicode-capable DejaVu Sans for dependable glyph support.

If a link is lost/expired, the applicant contacts the team for verified administrator assistance. A trusted operator verifies the contact out of band and runs:

    php artisan ideyra:application-receipt APPLICATION_REFERENCE --verified-email=verified-applicant@example.test

Only a private handoff path is printed. A new signed receipt expires after 72 hours. Old signed links remain valid only until their own expiry; there is no public email/reference enumeration recovery endpoint.

To migrate to a real official sender/recipient, verify the owned domain with a genuine provider, publish its required SPF/DKIM/DMARC records, set MAIL_* provider credentials and independently choose both IDEYRA_*_EMAIL recipients. Clear/rebuild Laravel configuration cache, then verify real delivery, sender, Reply-To, failure behavior and provider events using controlled inboxes. Example official-domain addresses in earlier documents are not ownership claims. No real provider or credentials were invented.

## Audit and security

Sensitive creation/status/review/role/grant/assignment/activation/reset/configuration operations are audited. Institution audit queries are explicitly scoped; personal profile/reset events and platform application notes are not exposed to Institution Administrators. Audit metadata contains identifiers/field names/outcomes, not passwords or bearer tokens.

CSRF, login/application/link throttles, active-account enforcement, context checks, database uniqueness/composite foreign keys, transactions/locks and no-store responses apply to the implemented workflows. System administration has both route-level Gate enforcement and controller checks. Private receipts/application/admin/identity HTML, PDF and form submissions are never added to the PWA cache or queued for offline replay.

The original public website, official logos, motion preferences, responsive behavior, contact form, static PWA cache/update strategy and offline fallback remain. Public copy and sitemap now direct users to applications, not self-registration.

## Files and verification

Primary additions:
- Models/factories for Institution, Membership, Role, Permission, JoinApplication, IdentityInvitation, AcademicYear, Programme, Intake, AcademicClass and AuditEntry.
- Application/identity/institution/membership/academic/system controllers; active-account middleware and application FormRequest.
- InstitutionAccess, IdentityOnboarding, ApplicationSchema, ApplicationWorkflow, AcademicWorkflow and Audit support classes.
- RolePermissionSeeder; verified bootstrap/provision/activation/receipt commands.
- Application/invitation/update mailables and HTML/text views.
- Applications, identity, institution and system views; admin layout/select field; application wizard JS and administration CSS.
- PhaseOneTest, PhaseOneSecurityTest, browser flows and isolated fixture preparation.

Existing entrypoints, metadata, authentication, routes, public CTAs/copy, field component, regressions and Composer lockfiles were updated. The obsolete public registration form/request/action and old unused dashboard view were removed.

Executed: Composer install/compatibility resolution; additive migration; dedicated seeder; route inspection; PHPUnit; production build; Pint; Blade compilation/clear; git diff --check; Chrome tests.

Latest full Laravel run: 64 passed, 577 assertions. The final scoped Phase 01 run also passed (20 tests, 175 assertions). The 11-scenario full Chrome suite passed, including existing animation/reduced-motion/no-JavaScript/contact/PWA checks and new application/PDF/system/institution workflows at 1440/768/390/360px. The refined explicit onboarding browser flow was rerun separately and passed. Production build, Pint, Blade compilation and diff checks passed.

PHPUnit uses in-memory SQLite. Browser fixtures enforce a browser-*.sqlite path under storage/app, use randomly generated per-run passwords and array mail, and never create default administrators in MySQL. Browser test files are synthetic artifacts, not deployment seeders.

Screenshots: storage/app/phase01-{institution,student}-review.png and phase01-{1440,768,390,360}-permissions.png. Machine-readable results are stored in storage/app/phase01-*-tests.json / phase01-final-checks.json. Production assets are generated under public/build.

## Known limits and next phase

- No real System Administrator was selected without explicit identity authorisation; use the verified bootstrap flow.
- Actual inbox delivery, physical-device performance checks and a full accessibility audit remain pre-launch tasks.
- Log-mailer development activation/reset requires trusted private handoff; configure and verify real delivery before ordinary remote onboarding.
- Bulk CSV import, institution billing/subscriptions, project/opportunity/portfolio business modules, payments, sponsorship processing, automatic mail retries/provider delivery webhooks and applicant self-service receipt recovery are not implemented.
- Profile email changes require future reverification. Local account/receipt handoff files and development mail logs require strict access/retention management.
- Existing legacy identities were preserved; review their legitimacy operationally before institutional assignment. They gain no institutional access by existing alone.
- Audit retention, deployment-specific privacy notices, domain/email ownership and institutional authority verification require real operational decisions; no fabricated policy or partnership was added.

Recommended Phase 02: implement the institution-scoped Idea Vault / Project Studio foundation with explicit ownership, collaboration grants and contextual project groups. Wire create/review/approve/sponsor actions separately when those workflows exist; do not infer financial rights from sponsorship capabilities.
