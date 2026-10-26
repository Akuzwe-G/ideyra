# Phase 07 — Controlled Innovation Network

Implemented in the existing Laravel application on 26 October 2026. Existing data and `.env` were preserved. Public account registration remains disabled.

## 1. Features implemented

Innovation Showcase, reviewed publication, cross-institution discovery and requests, isolated shared collaboration workspaces, innovation challenges, independent judging, events/exhibitions, explicit recognitions, moderation and network intelligence are integrated into the existing application shell.

Interfaces include discovery filters, approved project detail, publication editor/review/consent, collaboration discovery/requests/approvals/invitations, eight shared workspace sections, challenge editor/detail/registration/submission, judge dashboard/results, event editor/registration/gallery/participant management, recognition management, institutional policy and moderation.

The existing Montserrat typography, official logos, Lucide icons, branded controls, light/dark modes and responsive navigation are reused. Forms use aligned grids and readable stacked layouts on mobile. Unapproved or empty collections display truthful empty states.

## 2. Models and migrations

Migration: `2026_10_26_120126_create_controlled_innovation_network.php`.

27 new tables hold publication settings/projections/media/consents/decisions; collaboration requests/approvals/workspaces/members/entries/files; challenge specialisations/criteria/judges/submissions/versions/evaluations/conflicts; event specialisations/registrations/exhibition assignments; moderation reports/blocks; recognition records; encrypted workflow history and notification deduplication.

Existing opportunities gain `network_type`; opportunity applications gain an optional collaboration workspace reference. Existing users, institutions, projects, academic groups, applications, professional profiles, achievements and notifications remain authoritative.

21 Eloquent models have factories and relationships. Access and workflow services, policies, form requests, controllers, foreign keys, unique constraints, pagination and transactional writes separate the modules.

The live migration and PhaseSevenSeeder ran successfully. Before migration, a private, ignored database backup was written to `storage/app/phase07-before-migration-private.sql`. No refresh/reset, demo records or test accounts were applied to the development database.

## 3. Permissions

Institutional permissions:

- `showcase.submit`, `showcase.review`, `showcase.publish`, `showcase.unpublish`, `network.policy`.
- `collaborations.request`, `collaborations.review`, `collaborations.manage`.
- `challenges.create`, `challenges.publish`, `challenges.manage`, `challenges.judge`.
- `events.create`, `events.publish`, `events.manage`.
- `network.achievements_confirm`, `moderation.review`, `innovation-analytics.view`.

Platform permissions: `moderation.review_platform`, `innovation-analytics.view_platform`.

The seeder adds initial grants once, preserving later role customisation and explicit member denies. Institution Administrators receive institutional capabilities; students receive showcase submission/request capabilities; lecturers and mentors receive submission, review, requests and judge capabilities. A judging permission alone does not grant a challenge assignment.

Granting another shared-workspace administrator additionally requires institutional `collaborations.manage` delegation authority; ordinary collaboration membership is insufficient.

System Administrators receive platform moderation and aggregate intelligence, without automatic membership in student projects or shared workspaces. Existing permission delegation and scope restrictions remain in force.

## 4. Showcase workflows

A current project owner or authorised group leader prepares an explicit projection. No private task, document, deliverable or supervision content is copied.

Draft → submitted → under review → approved → published; return, rejection, unpublication and archival are supported. Approval excludes the submitter and current project contributors. Publication requires the approved version, valid individual attribution consent and current institution policy.

Private is the editor default. Institutional, network and public audiences require the appropriate approval and policies. Network/public participation defaults to institutional opt-out.

Approved snapshots contain only selected presentation fields, declared outcomes, media references and authorised attribution. Search filters apply after audience scoping. Hidden institution attribution cannot be inferred through the institution filter.

Every named contributor consents to a particular version. Media edits invalidate approval and attribution consent. Revoked names disappear on the next request. Unpublishing or disabling an audience blocks future detail and image requests immediately.

Ownership/licensing fields are declarations. Disclosure acknowledgements cover confidentiality and patentability. Publication transfers no ownership, grants no licence automatically and never publishes source code.

## 5. Collaboration functionality

Structured private requests record type, motivation, skills, contribution, optional supporting reference, expiry and decisions. Duplicate active requests, self-requests, abusive blocked requests and excessive mutation traffic are prevented.

A project representative accepts or declines. Both institutions must approve independently where their policy requires it. Requesters and accepting representatives cannot provide the independent institutional decision.

An accepted request creates one separate shared workspace. It never creates original-project membership. Initial parties administer the shared workspace; further participants receive invitations and must accept.

Overview, members, discussions/replies/mentions, tasks, milestones, private files, updates and activity history are available. Entries can be edited/removed; assignments and completion are scoped to current members. Workspace closure leaves read-only history for current authorised members.

Membership removal, institution suspension, network opt-out and workspace restriction revoke access, including downloads. `ideyra:network:expire` idempotently expires overdue requests; an hourly schedule is registered.

## 6. Challenge functionality

Challenges specialise one existing Opportunities Hub record. Institution organisers define rules, opening/deadline/event dates, eligible active student participation, individual/group/cross-institution participation, optional awards, audience and criteria with weights totalling 100%.

Publication requires independent approval. Registration reuses OpportunityApplication and its participant consent model. Cross-institution teams use accepted collaboration memberships and record each participant’s actual institution/programme. Their applications are excluded from the original institution review policy and use dedicated challenge checks.

Confirmed participants submit an explicit private project representation and optional permitted supporting material. Revisions preserve encrypted immutable versions. Deadline and eligibility checks run server-side; withdrawal is permitted before the deadline. Submission never creates a showcase publication.

Organisers explicitly assign authorised judges. Judges access only current assigned challenge entries during evaluation, subject to conflicts. Self-involvement and declared conflicts prevent scoring. Each criterion supports a score/comment; evaluations support confidential overall feedback, drafts and immutable final submission. Scoring changes are audited without putting private comments into general audit metadata.

Preliminary, final and published results are distinct. Finalisation requires final evaluations from every eligible non-conflicted assigned judge, with at least one independent judge per entry. Results publish entry numbers, weighted scores and placements; private titles, names and judge comments remain excluded. Currently visible approved showcases may be linked.

## 7. Events and exhibitions

Events support independent approval, venue/authorised online location, IANA timezone, UTC storage, capacity, registration requirements, agenda, visibility, publication, attendance and explicit completion.

Standalone events use one specialised opportunity. A challenge-linked exhibition reuses the challenge’s opportunity, with uniqueness preventing duplicate linked events.

Registrations are idempotent and capacity is checked under a database lock. Existing challenge applications are linked where present; RSVP does not create a duplicate application. Cancellation is supported before the start. Only authorised organisers record attendance after the event begins.

Exhibitions feature existing approved projections compatible with the event audience, with category, display ordering, current representative, time slot and demonstration link. Revoked publications disappear from the gallery on subsequent requests.

Rescheduling validates the new window, clears obsolete agenda slots, audits the change and notifies registered participants.

## 8. Professional portfolio integration

Independent authorised organisers confirm evidenced challenge results, completed-event attendance or completed contributions in closed collaborations.

Confirmation creates a professional achievement entry and the existing Phase 04 verification record. It does not follow automatically from registration, attendance or a self-report.

Recognitions default to private. Owners must allow public use and separately select/publish the achievement in their portfolio. Entry changes invalidate the approved payload comparison. Revocation removes the public verification permission immediately.

Organisers choose authorised completed sources and recorded eligible participants. No public student directory or contact list is created.

## 9. Opportunities Hub integration

Specialised challenges/events remain discoverable and bookmarkable in the existing hub under current audience rules. Opening a specialised listing leads to its challenge/event experience.

Generic opportunity editing/publication cannot bypass specialised approval. Event RSVP uses the event workflow; challenge applications reuse existing application records and consent rather than duplicating them.

Existing external application handling and sponsorship workflows remain unchanged.

## 10. Notifications

Database notifications cover publication/consent/review, collaboration requests/approvals/invitations/membership, challenge registration/submission/evaluation/results, event registration/rescheduling and recognitions/moderation.

Per-recipient event keys suppress duplicates. Messages contain generic workflow information. Opening a notification rechecks current access; revoked links return safely to the notification centre.

These are in-app notifications. External email delivery is not claimed or enabled by this phase.

## 11. Analytics and charts

Phase 06 gains an Innovation network tab, metric registry entries and an Innovation Network Report. KPI actuals can reference authorised network metrics.

Actual database aggregations provide published projections/institutions, received requests, accepted active/initiated collaborations, challenges, submissions, distinct challenge/event participants, hosted events and confirmed recognitions.

Six existing Chart.js components display publication growth, category doughnut, request statuses, collaboration growth, participants by programme and event participation trends. Date filters, institutional/academic scope, privacy protection, accessible tables, keyboard controls, themes, PNG and authorised CSV export reuse Phase 06 infrastructure.

Requests, accepted collaborations, registrations, attendance and confirmed recognitions are separate measures. No activity count is presented as proof of innovation impact. Empty and privacy-protected data produce clear messages instead of misleading charts.

Network report generation, snapshots, download access and schedules require current network authority as well as the existing reporting/export permissions.

## 12. Moderation

Users may report accessible showcase publications, requests and shared discussion entries. Reports are encrypted and visible only to authorised institutional/platform moderators.

The moderation screen shows reported content for review while withholding reporter identity. Independent moderators dismiss reports or remove/restrict content, optionally restricting further requests for a bounded period. Decisions and reasons are recorded; private report/score content stays out of general audit metadata.

## 13. Security and privacy

Policies and service checks enforce active identities, institutional permissions, independent decisions and current collaboration membership. System role alone grants no access to original private project material.

Public routes render controlled approved projections only. Supporting submissions and shared files use private storage and authorised streaming. Public image and private file responses disallow caching and sniffing. Network pages are no-store/noindex. Public registration remains disabled.

Publication images are type/dimension/size checked, downsampled, re-encoded and stripped of metadata. Permitted shared/submission formats are bounded to PDF, PNG, JPEG and text with a 5 MB limit.

Private snapshots, submitted versions, evaluations, reports, recognition evidence and workflow details use Laravel encryption. Unique constraints and transactional locks protect duplicate-sensitive workflows. All normal forms retain CSRF protection and privileged mutations are rate-limited.

Tests use guarded SQLite memory databases and separate browser SQLite fixtures. The existing development MySQL data was not reset or used for test fixtures. No credentials were added to source or documentation.

## 14. Test results

- Full PHP regression: **244 passed, 1,992 assertions**, using the guarded isolated test database.
- Final scoped security/collaboration checks: **13 passed, 124 assertions**, including authorised recognition source/participant selection, independent delegation and access revocation.
- Request history/cross-institution collaboration checks: **4 passed, 28 assertions**.
- Network aggregation/report checks: **5 passed, 20 assertions**, including unique student/staff event participants, cancelled/foreign exclusion and revoked report-download authority.
- Browser regression: **all 36 cases verified passing**. The full run passed 35 cases; its remaining responsive-shell assertion checked overflow during viewport resizing. Changing that assertion to poll for layout settling preserved the overflow check, and the targeted rerun passed. All three new Phase 07 browser journeys passed in the full run.
- Browser checks covered public projection/revocation, real-data line/doughnut rendering, empty analytics, event approval/registration, mobile widths, themes, existing authentication/administration/workspace modules, professional profiles/CV PDFs, portfolios, AI, opportunities, reporting and PWA behaviour. The new browser journeys reported no JavaScript page errors.
- `php artisan view:cache --no-interaction`, Laravel Pint and `git diff --check` passed.

Private/ignored artifacts include `storage/app/phase07-full-tests.json`, `phase07-final-security-tests.json`, `phase07-history-tests.json`, `phase07-analytics-tests.json` and the Phase 07 screenshots. No test data was written into the development database.

## 15. Production build results

`npm.cmd run build` succeeded with the existing installed Vite and Chart.js dependencies. No dependency installation or package changes were required. Blade compilation and Laravel Pint formatting succeeded.

## 16. Known limitations and operational requirements

- Institutions must explicitly opt into network/public discovery; no institution or project is automatically exposed.
- Public audiences permit discovery, not anonymous registration or unrestricted messaging.
- External email transport, background workers and an operating-system scheduler were not configured or claimed as operational. Run the normal Laravel scheduler for hourly request expiry; deadline checks still reject expired actions immediately.
- Supporting files are intentionally limited to the allowed formats and size. Publication images are optimised JPEG representations.
- Ownership declarations and organiser confirmation are not legal IP determinations. Judging remains human and evidence-based.
- No payments, investor transactions, public member directories, social messaging or automatic verified awards were introduced.

## 17. Recommendations for Phase 08

Validate institution participation policies with authorised stakeholders before enabling wider discovery. Configure and observe the scheduler/queue/email infrastructure appropriate to the deployment. Continue accessibility and usability review with student representatives, independent judges and institutional moderators, preserving the explicit approval, consent and revocation boundaries.

