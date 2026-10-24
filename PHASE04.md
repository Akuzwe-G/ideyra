# IDEYRA — Phase 04 implementation report

## Completed scope

Phase 04 extends the existing Laravel 13 application. The existing branding, authentication, account activation, institutional permissions, Project Studio, Opportunities Hub, charts, notifications and private response conventions are retained. No dependency was added, public registration was not enabled, and `.env` was not overwritten.

The professional identity workspace is available at **Career & Profile** in the authenticated sidebar and from the personal dashboard. Institution workspaces link to authorised career aggregates.

## Professional profile

The professional profile is separate from login/account settings. It supports professional display name, headline, biography, optional location/email/phone, photo, website, LinkedIn, GitHub and another professional link. The private account email is never a public fallback.

Tabbed management supports:

- Education: institution text, qualification/programme, field, start/end or expected graduation, current-study flag and description.
- Skills: name, category, optional proficiency, evidence description/link/file and independent verification requests.
- Experience: organisation, role, engagement type, dates/current engagement, responsibilities, achievements, link and private evidence.
- Certifications: issuer, issue/expiry dates, credential identifier, verification link and private evidence.
- Achievements: title, category, date, issuer, description, evidence and independent verification.
- Languages and proficiency.

Records have display order, ownership checks, edit/remove controls and scoped search. Institutional names in self-authored education are self-reported; they do not become verified academic records.

Supporting evidence is private. Permitted evidence files are PDF/PNG/JPEG/WebP up to 10 MB. Photos/showcase images are restricted to PNG/JPEG/WebP, 4 MB and 4,000-pixel dimensions. With the installed GD extension, publication images are resized to a maximum 1,600-pixel side and re-encoded as PNG, removing original metadata. If GD is unavailable, the validated original is stored privately.

## Deterministic completeness

Eight equally weighted checks determine completeness:

1. Professional display name.
2. Headline.
3. Biography.
4. At least one education record.
5. At least one skill.
6. At least one accessible showcased completed project.
7. At least one experience record.
8. At least one achievement or certification.

The percentage is `round(completed checks / 8 × 100)`. Missing checks generate suggestions. Optional contact fields do not affect the score. Completeness does not restrict any other module and is not an employability score.

## Project Studio integration

Showcases reference authoritative Phase 02 projects rather than copying project ownership. Roles derive from current project/group records: project creator, project leader, team member, contributor or supervisor. Merely viewing an institution-wide project does not establish participation.

Showcases display the project's title, description, problem, existing proposed outcomes, category, status/completion date, technologies, actual role, authorised links and institutional attribution. Personal reflections are labelled self-reported.

Personal project creators can explicitly select their own projects for publication. Participation in another person's personal project does not grant public sharing rights.

All institution-linked projects require an independent institution-approved `academic_project` verification with public sharing permission. Approval covers the exact project/showcase content and role. Editing that content, changing roles, losing project access, suspending membership/institution, hiding sharing approval or revoking verification removes the project from public output. Approved public assets are selected at publication and checked again on every request.

Media uses private storage, alternative text, ordering, removal/replacement and explicit public approval. Institutional media approval requires authorised institutional staff. Private media/evidence URLs are never included in public portfolio data.

## Verified achievements and contributions

Supported verification categories are academic project/publication, leadership, participation, institution-confirmed achievement, award, certificate and skill evidence.

Lifecycle: unverified (no decision), requested, under review, verified, rejected and revoked. Only current authorised institutional staff can decide requests; self-verification is forbidden.

Institution Administrators with `career.verify` review their institution. Lecturers/mentors require the explicit grant and current supervision of the relevant project. A Student grant alone does not create institutional reviewer authority. Explicit permission denials and inactive memberships/institutions are enforced.

Requests record the subject, consent to sharing, evidence statement and an immutable private copy/hash of any supporting file. Decisions record institution, verifier ID, date, encrypted reason/evidence reviewed and history. Approval fails if the subject changed after the request. Rejected/revoked/completed records can receive a fresh owner-requested review; previous evidence is retained in encrypted decision history and a new public reference is issued. A pending duplicate request is rejected.

The owner must request public permission and the reviewer must approve it. Verification alone never publishes a profile or portfolio.

The opaque public reference `/v/{reference}` is available only for records explicitly included in a published portfolio. It reveals title, current status, issuing institution, verification date and revocation status. It never reveals the claimant's private contact information, evidence documents, academic records or reviewer notes. Previously public references can show a later revocation; hiding the reference or unpublishing the portfolio removes access.

## CV Builder and PDF export

Students can create multiple named CV documents, choose a template, import their professional profile explicitly, include/exclude and order sections, edit CV-specific content, preview current unsaved fields, save versions, duplicate, rename, delete with confirmation and export PDF.

Templates:

- **Classic Professional:** formal heading hierarchy and clean single-column reading order.
- **Modern Graduate:** contemporary blue accent with stronger project placement.
- **Technical Portfolio CV:** structured teal/navy styling with skills/projects earlier in the default order.

Content supports contact details, summary, education, skills, experience, projects, certifications, achievements, languages and optional references. Empty sections are omitted. Import does not invent content and excludes unapproved restricted projects.

Each save records an encrypted immutable version. Source profile changes never overwrite a saved CV; CV edits never overwrite the source profile. Owners can preview/export historical saved versions. Deleting a CV deletes its standalone version history after confirmation, while already-submitted application copies remain independent.

Near-live preview renders the current form through the same document view used for export. A fitted A4 paper preserves page width and margins, with a 100% size option for reading. Its loading state clears when the preview frame finishes, allowing repeated updates.

The existing `barryvdh/laravel-dompdf` 3.1.2 wrapper is reused with print media explicitly selected, so screen-only paper sizing cannot introduce trailing blank pages. PDFs use A4, 18/17 mm margins, readable embedded DejaVu fonts, explicit page-break rules and clickable HTTP/HTTPS professional links. Short CVs in all three templates export as one page; long CVs paginate across A4 pages. Remote resource fetching and PHP execution are disabled. The web preview uses Montserrat and the same document structure; font metrics can differ slightly between browser print engines and Dompdf.

CV text is self-authored professional material, not an institutional endorsement of the entire document. CV routes and snapshots are owner-private; administrators receive no general read capability.

## Optional portfolio and themes

A portfolio starts unpublished with a generated unique slug. The owner can choose/update the URL, select and order visible sections, explicitly select records/projects, choose a theme, preview privately, publish with confirmation, publish updates and unpublish.

Themes:

1. **Ideyra Minimal**
2. **Creative Innovator**
3. **Technical Builder**

Public URL: `/p/{slug}`. All themes support responsive layouts, light/dark preference, keyboard focus, reduced motion and meaningful image alt text. Official branding, Montserrat and installed Lucide assets are reused.

Public output is an explicit publication snapshot. Profile/CV edits do not silently update it. Saving portfolio settings unpublishes the page until the owner previews and confirms publication again. Contact fields/photo are individually opt-in. Project permissions, current asset approval, deleted entries, removed/replaced photos and verification visibility are rechecked dynamically.

There is no public student directory or public portfolio search. Indexing is disabled by default. An owner can explicitly allow indexing of a published portfolio. Robots controls cannot guarantee removal from third-party indexes or copies.

Changing a slug makes its previous address unavailable; the previous slug is not reserved indefinitely.

## Portfolio contact and private inbox

The owner may separately enable a public contact form and/or select a public professional email link/social links. Both default to private/off.

Contact forms enforce server-side validation, CSRF, an empty honeypot, a portfolio-bound encrypted token, a two-second minimum completion time, two-hour expiry, replay deduplication and five submissions per ten minutes per requester. Only published portfolios of active users with contact enabled accept messages.

Visitors explicitly consent to sharing their name, email and enquiry with the owner. Sender details and message bodies are encrypted in the database. Only the portfolio owner can read, mark read or delete inbox messages. Notifications contain generic notices and a protected inbox link.

Delivery is to the private Ideyra inbox. No external email delivery is claimed or enabled by this feature. No visitor IP analytics or fabricated view counts were added.

## Opportunities Hub integration

Internal application forms allow explicit selection of:

- An owned CV and its current saved content.
- Individually chosen professional profile fields.
- Authorised showcased projects with sharing approval.
- Current verified achievement summaries.

Nothing is selected automatically. Only included CV sections are copied into shared application material; excluded CV content remains in the private source document. Only the representative's own chosen material is included for group applications. Participating members can inspect these shared materials before consenting.

Saving selections copies the selected material into the encrypted draft. Meaningful changes, including refreshed CV content, reset group consent. Submission rechecks project access/approval and achievement verification. The final encrypted application version includes an independent CV/material snapshot.

Later source CV edits or deletion do not change the submitted copy. Existing authorised reviewers can export the CV from a specific submitted application version. Unsubmitted revisions remain private. Historical application materials retain the state explicitly shared at submission, including verification dates; they are not silently rewritten after later revocation.

External opportunities retain their official external link flow. Students may download a CV and apply themselves; Ideyra does not submit to third-party services.

## Real analytics and institutional privacy

Personal dashboard:

- Completeness, skills, accessible showcases, verified records, private CV document count and publication status.
- Skills by category — doughnut.
- Accessible projects by current status — bar.
- Recorded achievement timeline — line.

Institutional aggregates require a current `career.report` grant and active institution membership:

- Current active students with profiles, published portfolios, verified institution-linked records, institution-linked showcases and saved CV adoption.
- Profile adoption timeline.
- Verified achievements by category.
- Portfolio publication-event trend.
- Unique showcase participants by programme.

The existing Chart.js library supplies responsive charts, themes, keyboard support, legends, tooltips, PNG export and accessible data tables. Date ranges/grouping and authorised CSV export are supported. Empty datasets show explanatory empty states.

KPI cards show current totals. Chart notes describe creation/decision/publication date cohorts. Institution membership denominators use current active Student memberships/users. Programme counts deduplicate a student within a programme; a student in multiple programmes can appear in each. Publication trends count explicit unpublished-to-published events, including later re-publication, not visitors or unique people.

Institution reporting exposes aggregates, not private CV contents, unpublished portfolio contents, reflections, personal contacts or supporting files.

## Architecture, models and migrations

Two additive migrations create **11 tables** and extend existing applications:

- `professional_profiles`
- `professional_entries`
- `project_showcases`
- `achievement_verifications`
- `verification_decisions`
- `cv_documents`
- `cv_snapshots`
- `portfolios`
- `portfolio_media`
- `career_publication_events`
- `portfolio_messages`
- Existing `opportunity_applications.career_selection` stores encrypted selected career material.

Nine models with factories: ProfessionalProfile, ProfessionalEntry, ProjectShowcase, AchievementVerification, CvDocument, CvSnapshot, Portfolio, PortfolioMedia and PortfolioMessage.

The six typed professional entry categories share one structured table and validated per-type content schema. This avoids six repetitive CRUD implementations while keeping sections independently managed.

Services: CareerAccess, CareerWorkflow, CareerMaterials and CareerAnalytics. Dedicated profile/entry/CV/portfolio/contact Form Requests enforce schemas. ProfessionalProfilePolicy controls owner access; scoped existing Project/Opportunity policies and explicit current authority checks protect integrations. Transactions, unique constraints, foreign keys, lock-protected version writes and audit records preserve integrity.

Public assets remain in private local storage and are streamed through current publication checks, with no-store and nosniff headers. Encryption depends on the existing APP_KEY; preserve it with database/file backups.

## Routes, permissions and notifications

Primary authenticated routes:

- `GET /career` with overview/profile/education/skill/experience/certification/achievement/language/project tabs.
- `POST /career/profile` and owned entry/showcase/media management endpoints.
- `/career/verifications` plus request, decision, visibility and private evidence actions.
- `/career/cvs`, create/edit/save/duplicate/import/delete/preview and PDF query actions.
- `/career/portfolio`, private preview, explicit publication and private inbox actions.
- `/career/analytics/{chart}/csv`.
- `/career/applications/{application}/versions/{version}/cv`.

Public routes are limited to the published `/p/{slug}` page, checked photo/media/contact endpoints and minimal `/v/{reference}` metadata.

New institution permissions: `career.verify` and `career.report`. PhaseFourSeeder adds these metadata/default Institution Administrator grants once, preserving existing customised roles and membership overrides. Lecturers/mentors can receive explicit grants through existing administration.

Notifications cover verification requests/decisions/revocation, publication status transitions and new private enquiries. Replayed contact submissions and pending verification duplicates do not fan out duplicate messages. Delivery uses the existing database notification centre.

Successful Phase 04 actions use explicit workspace return routes, avoiding incorrect navigation when the existing no-referrer policy suppresses browser referrers.

## Database upgrade and verification

A private ignored MySQL backup was taken before the additive upgrade. Both migrations and PhaseFourSeeder were applied successfully to the existing database. Existing data, accounts, branding and `.env` were preserved. No development/demo records were seeded into the live database.

PHP tests use guarded in-memory SQLite. Browser tests use isolated timestamped SQLite files and random ephemeral credentials. Neither test runner refreshes the live MySQL database.

Final verification results:

| Check | Result |
| --- | --- |
| Full PHPUnit regression | 160 passed, 1,445 assertions |
| Full Playwright regression across Phases 01–04 | 26 passed |
| Final Phase 04 browser rerun after A4/privacy refinements | All 3 journeys passed; no JavaScript console errors |
| Focused CV checks | 4 passed, 71 assertions; all three short templates fit one A4 page, long content paginates and links remain clickable |
| Final scoped media/revocation check | Passed, including direct private/public media and verification references after membership suspension |
| Final contact validation/rate checks | Passed; contact uses an independent rate-limit prefix |
| Production Vite build | Passed |
| Laravel Pint | Applied successfully |
| Blade view compilation | Passed |
| Git whitespace check | Passed |
| Existing MySQL database | Both Phase 04 migrations and permission seeder applied successfully |

Browser coverage includes repeated near-live CV previews, fitted/100% A4 viewing, three CV templates, three portfolio themes, publication controls, privacy, independent verification/revocation, private contact delivery and layouts from 360 px to 1,440 px. Existing login, academic administration, dashboards, Project Studio, Opportunities Hub, applications, sponsorship, notifications, public website and PWA regressions passed.

## Known limitations and Phase 05 recommendations

- Verification represents an authorised human decision about specified evidence. External certificate authenticity is not checked by a third-party credential service.
- Basic contact spam controls are implemented; a monitored abuse/moderation workflow or additional challenge mechanism may be appropriate for high-volume public deployments.
- External email delivery is not part of this phase. Contact messages work through the private Ideyra inbox.
- Public views/visitor tracking are not implemented; no view or visitor analytics are fabricated.
- Uploaded evidence does not have antivirus scanning or institution storage quotas. Add those with a defined operational policy.
- Verification history prevents deleting referenced entries/showcases outright; the owner can remove them from public selection and hide references. Broader retention/anonymisation rules remain a product decision.
- Institution-linked public showcases require current access and exact content approval. A durable alumni sharing policy would require additional explicit rules.
- Previously shared downloads or third-party copies cannot be recalled by unpublishing. Current application access and public requests continue to enforce current authorisation.
- CV templates support professional text and links, not arbitrary rich HTML or embedded uploaded documents. PDF font metrics may differ slightly from browser preview; uncommon scripts may need additional approved font coverage.
- Keep existing scheduler/worker production operations under deployment review; this phase does not configure an operating-system scheduler or claim external mail delivery.
- Phase 05 can build consent-based career guidance and richer opportunity workflows on these explicit profiles/snapshots, after product approval. No AI writing/assessment/matching, recruiters, public directories, paid CV subscriptions, payments or external credential integrations were introduced.

