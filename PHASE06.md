# Phase 06 implementation report

## 1. Features and entry points

Institutional Intelligence is available at `/intelligence` from the administration navigation and existing dashboards. System Administrators retain `/system` as their login destination and do not receive personal workspace navigation. Eligible institution staff select their institution; lecturers and mentors receive assigned-class scopes. Student personal dashboards remain separate.

The module includes platform/institution overviews, project performance, participation, programme context, opportunity engagement, career adoption, verified achievements, AI metadata, data quality, institutional comparisons, KPI management/scorecards, report preview/history/downloads and scheduled reports. Existing Chart.js, notification, audit, academic and opportunity-audience services are reused.

## 2. Models and migrations

Added `InstitutionKpi`, `KpiChange`, `KpiObservation`, `ReportingSetting`, `ReportRun` and `ReportSchedule`. The main migration creates seven tables, including daily institution-linked opportunity visits, plus project/idea aggregation indexes. The second migration adds nullable, indexed `ideas.first_submitted_at`. New submissions retain their first actual event time across resubmissions. Legacy rows are not backdated.

Both migrations were applied to the existing MySQL database as batches 13 and 14. `PhaseSixSeeder` adds only permission metadata/default role grants and records its initialization. Repeated runs preserve later role customisations and membership denials. No development records, example KPIs or reports were seeded into the live database. A private, git-ignored SQL backup was created before the migrations. The existing `.env` was not edited.

## 3. Analytics architecture

`IntelligenceAccess` authorises scope before queries or cache access. `IntelligenceFilters` validates dates and institutional academic relationships. `IntelligenceMetrics` performs database aggregation; controllers do not duplicate formulas. Chart data, report data and automatic KPI actuals use the same registry in `config/intelligence.php`.

Protected aggregates are cached for up to 60 seconds, keyed by definition version, privacy threshold, viewer, authorised classes and filters. Source rosters and private contents are not cached by this service. Current permission checks precede every cached response. Record drill-downs also intersect original module permissions. A platform aggregate grant does not provide private source-record access.

Queries aggregate counts/unions in SQL rather than loading student, project or application populations into PHP. Grouped charts return at most 100 categories; horizontal bar visuals show the first 15 with all returned categories in accessible tables. KPI lists and report history are paginated; recent KPI change/observation histories load at most 20 entries per displayed KPI. XLSX/CSV rows are written incrementally. KPI report snapshots contain definition/score metadata, not source student datasets.

## 4. Definitions and time

- Reporting dates use `Indian/Mauritius`, converted to half-open UTC database intervals. Daily, Monday-based weekly and monthly buckets use the same local timezone. Ranges are limited to three years.
- Students/members are distinct, currently active identities and memberships joined by period end. Academic assignment/status history is not reconstructed. Institution/group/class relationships are current recorded relationships.
- Project creation and completion are separate events. Completion counts include older projects completed within the selected period. Current project/task/milestone/deliverable status is explicitly labelled; it is not a historical state reconstruction.
- Unique project participants union owners, explicit non-supervisor members and group members, intersecting the current institutional Student cohort. Multiple projects or duplicate relationship paths do not multiply people.
- Completion duration uses actual completion minus recorded start, falling back to creation. Missing/negative durations are excluded. Target dates are used only for deadline comparisons. Ratios with no denominator remain unavailable.
- Idea conversion uses a creation cohort matured by the configurable lag (30 days by default). Converted ideas are counted distinctly. Recent ideas are excluded rather than classified as failed. First-submission events use recorded timestamps; legacy review evidence is labelled separately in the cohort journey.
- Internal submissions, shortlisting and acceptance are reviewer workflow outcomes. External applications remain explicitly self-reported. Institutional achievement verifications are a separate category of recorded reviewer decisions; acceptance is not relabelled as independently verified external success.
- Application-by-programme charts count each application once per current applicant programme. Multiple assignments may overlap; group co-participants do not change applicant-programme attribution.
- Career adoption counts metadata only. Reporting profile readiness requires display name, headline, biography, an education entry and a skill entry; it is distinct from the personal profile's richer completeness indicator. No CV body, portfolio draft, private reflection or evidence contents are read for analytics.
- AI analytics read request metadata, status and reported tokens/configured cost estimates. Personal-context requests and conversation contents are excluded. Usage is not an achievement score. Existing live daily quota reporting remains available through the AI section; its UTC operational day is clearly separate from the reporting date range.
- Platform activity is institutional idea creation + project creation + internal submissions + institutional verification decisions. It counts events, not people.

Filters affect the applicable domain: project status/category affect project-linked measures; opportunity category affects opportunities/applications. Academic filters constrain student cohorts and their attributable activity. Published-opportunity and AI metadata scope is explained in the interface. Historic ownership, membership and status snapshots that were never recorded cannot be recovered.

## 5. KPI management

KPIs include category, description, source, registry metric/manual method, academic scope, owner, period, direction, target/range and notes. Automatic actuals cannot be submitted or overridden through forms. Manual observations append dated, attributed evidence; evidence text is encrypted and optional attachments remain private.

Lifecycle (`draft`, `active`, `closed`) is separate from performance (`achieved`, `on_track`, `at_risk`, `insufficient_data`). Increase, decrease and range calculations have explicit zero-target handling. On track means at least 80% before period end; an unmet ended-period target is at risk. Trend comparisons use the previous equal period, or the previous dated manual observation.

Independent approval is required by default. In-progress active changes still require an independent approver when the institution relaxes its future-definition policy. Requesters cannot approve their own changes. Version checks and row locks reject stale decisions. Closed definitions and ended active-period target rewrites are blocked. Reasons, previous/proposed values and decision attribution are retained and audited.

## 6. Scorecards

Scorecards group/sort by category and support title/period/target ordering, category/lifecycle/period filters, actuals, targets, achievement progress, owner, lifecycle, performance and trend. KPI report exports include direction, upper range bound, achievement, previous actual and trend. Individual KPI periods remain explicit when a report includes overlapping KPIs; the report date range selects definitions and does not silently redefine their approved periods.

## 7. Report Centre and history

All ten report categories are implemented: institutional overview, project performance, participation, programmes, opportunities, professional development, verified achievements, KPIs, AI usage and platform overview. The workflow supports authorised scope/filter selection, preview, synchronous or background generation, private history and authorised downloads.

Every successful run has an encrypted aggregate snapshot, saved filters, definition version, generation/expiry metadata and a unique file identifier. Regeneration creates a new run; existing ready files are never silently replaced. SHA-256 checks cover both file and snapshot at download. Source changes do not mutate historical snapshots. Access is rechecked against current permissions, membership and class scope. Pending generation fails closed if its saved academic scope has changed.

## 8. PDF, Excel and CSV

Existing Dompdf produces A4 confidential reports with title, institution/logo where available, period, filters, definitions, metric/chart tables, KPI summaries and generation metadata. Remote resources and PHP execution are disabled; DejaVu Sans ensures offline PDF font availability.

OpenSpout **4.28.5** was installed for XLSX because it supports this project's PHP 8.3 runtime. CSV and XLSX share the same row generator and formula-injection escaping. PNG chart exports remain client-side. Protected values remain protected in every format.

## 9. Scheduled reporting

Weekly/monthly/quarterly schedules store owner, scope, recipients, filters, format and next run. Runs cover the previous complete local calendar period; no automatic missed-period backfill is claimed. Recipient membership, permissions and complete report scope are validated. Dispatch rechecks authorisation, uses row locks and unique schedule/occurrence references, and queues `GenerateIntelligenceReport` on `intelligence-reports`. Invalid schedules are disabled with a safe status. Generation failures preserve a generic failure code and do not expose report contents in exceptions or logs.

Laravel registers hourly `ideyra:reports:dispatch` and daily `ideyra:reports:purge`. Reports expire after 180 days; current-time expiry blocks download even before cleanup removes files. Retention requires scheduler execution. Ready notifications are available in-app and link through normal report authorisation, without public attachments.

**Operational delivery is not verified on this machine.** No external email delivery or always-running worker is claimed. `.env.example` documents three default-false flags: `IDEYRA_REPORT_EMAIL_ENABLED`, `IDEYRA_REPORT_SCHEDULER_VERIFIED`, `IDEYRA_REPORT_QUEUE_VERIFIED`. These do not configure infrastructure themselves. Email is suppressed for `log`/`array` mailers even if flags are enabled. Manual generation/download works independently.

For deployment, configure the normal Laravel scheduler, a worker listening to `intelligence-reports` with a timeout compatible with the 180-second job and queue retry settings, and a tested mail transport. Verify dispatch, job completion and actual mailbox receipt before setting operational flags. Clear stale configuration with `php artisan config:clear`; rebuild with `php artisan config:cache` only after reviewing deployment configuration. No existing environment file is replaced by this phase.

## 10. Data quality

The health view flags missing student academic assignments, project ownership/status/class context issues, missing actual completion timestamps, invalid date order, duplicate membership relationships, missing verification metadata and legacy idea submissions without first-event times. Findings link to permitted, filtered source records. Source correction uses the existing workflow; reporting never deletes, fabricates or repairs source records automatically. Verification evidence completeness concerns metadata presence, not an automated assessment of confidential evidence contents.

## 11. Dashboards and charts

The platform overview has six requested analytical charts and 12 summary metrics; institution overview has its requested charts, the mature cohort journey and 13 summary metrics. Detailed sections avoid placing every registry metric on the overview. Charts support responsive layout, keyboard value navigation, pointer/Enter drill-down where provided, legends, tooltips, dark/light themes, PNG, authorised CSV, loading feedback, useful empty/protected states and accessible data tables. Animation is disabled and instances are destroyed on navigation.

Institution comparisons are alphabetical with eligible cohort size, project creation/completion, internal applications, unique participation, participation rate and portfolio publishers. Definitions and coverage warnings accompany them; there are no institution or student league tables.

## 12. Security and privacy

Dedicated analytics/export/report/KPI/quality permissions are checked server-side. Explicit membership denials remain effective. Students are excluded from institutional intelligence, including accidental analytics grants. Assigned staff cannot gain institution-wide scope from their role name alone. System Administrator platform aggregates do not bypass private personal workspaces, CVs, conversations or source record policies.

Counts of 1–4 people are suppressed. If a demographic chart contains a small cell, its entire breakdown is protected to avoid simple complement disclosure. Small numerator/denominator participation rates are unavailable. Suppression is applied before caching, charts, accessible tables and export generation. It is a privacy safeguard, not a claim of formal differential privacy.

Reports/evidence are stored on the private local disk. Report routes are authenticated, have current-access checks, `no-store` and `noindex` headers, and are excluded from PWA page caching. Download integrity, formula safety, escaped Blade output, disabled remote PDF content and restricted recipients are enforced independently of the navigation UI. Audit entries contain identifiers/filter metadata and actions rather than report bodies or personal content.

## 13. Verification

Targeted Phase 06 verification passed **27 tests / 165 assertions** before the final regression run. Coverage includes aggregation, deduplication, tenant/assigned-class isolation, date/time boundaries, missing values, mature cohorts, task/milestone/deliverable rates, internal/external outcomes, metadata privacy, first-submission capture, KPI approvals/versioning/manual evidence, real PDF/XLSX/CSV generation, snapshots, download tampering/revocation, recipient validation, queue dispatch, safe failures, retention and disabled email behaviour.

Browser checks exercise real rendered charts, mobile filters/themes, empty states, institutional sections, KPI creation, all three file formats, report history, scheduling and student denial. Browser fixtures use separate guarded SQLite files with random runtime passwords. Feature tests use guarded in-memory SQLite; no refresh/reset is run against the development database.

The complete PHP regression suite passed **217 tests / 1,803 assertions**. Final targeted KPI checks, including private evidence uploads/downloads, cross-institution denial and download audit records, also passed **6 tests / 36 assertions**. The complete Chrome browser regression passed **33 tests**, including existing modules, search-field styling, login layout, responsive/dark-mode charts, real report downloads and scheduled-report forms. Production Vite build and Blade compilation passed; Pint and `git diff --check` passed. Actual mail delivery, long-running queue/scheduler operation and external AI connectivity remain unverified infrastructure conditions. Live configuration was confirmed as `mail.default=log`, `queue.default=database`, with all three report-delivery flags false.

## 14. Known limits

- Legacy submission/activation/status histories and past class membership cannot be reconstructed. New first-submission timestamps improve future reporting without rewriting history.
- Current statuses are not historical event snapshots. Generation-time report snapshots are immutable representations of what was known when generated.
- Opportunity-view collection begins with this phase, for authorised eligible institutional Student contexts; historical visits are not invented.
- Tables/visuals have explicit category limits; extremely large KPI report collections and PDF tables should be monitored on production-sized datasets. No production load benchmark is claimed.
- Cache freshness is up to 60 seconds. Configuration changes affecting definitions should update the definition version or clear the application cache as appropriate.
- Scheduled email and retention automation depend on verified deployment infrastructure. In-app/manual functionality is available without asserting external delivery.

## 15. Recommendations for Phase 07

Establish deployment monitoring and tested scheduler/queue/mail operation first. Add durable academic assignment and status event histories if retrospective institutional reporting becomes a requirement. Validate metric definitions with institutional reporting owners before relying on them for policy decisions. Benchmark representative production volumes, then consider preaggregated daily facts or reporting replicas if needed; preserve the shared definitions and permission boundaries. Agree on retention, recipient and small-cohort policies before widening report distribution.
