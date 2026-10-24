# Ideyra Phase 03 — Opportunities, Applications and Project Support

## Delivery

Phase 03 extends the existing Laravel 13 application with a working Opportunities Hub, individual/group applications, publication and application review, bookmarks, deadlines, private supporting documents, non-payment project sponsorship, notifications and real-data analytics.

Existing identity onboarding, the public institutional Get Started portal, academic structures, Idea Vault and Project Studio remain in place. There is no public self-registration or external sponsor registration. No dependencies were added.

The additive migration and PhaseThreeSeeder were executed against the existing MySQL database after a private SQL backup was saved under ignored storage. Existing data was not reset and .env was not overwritten. The only catalogue data seeded is the requested category metadata; no opportunities, applications, sponsor contacts or commitments are fabricated in the development database.

## Entry points

The existing sidebar includes Opportunities Hub, Opportunity applications and Project support.

- Hub: discover published listings, filter, sort, save opportunities and open details.
- Manage listings: authorised creator/manager drafts and publication previews.
- Categories: platform System Administrator catalogue management.
- My applications: drafts, group consent requests, submitted histories, outcomes and external self-reports.
- Authorised review queue: opportunity application review within assigned authority.
- Project support: requests tied to currently authorised Phase 02 projects.
- Opportunity analytics: personal activity, authorised institution reporting or System Administrator platform aggregates.

Project overview and supervision pages link to related support and authorised opportunity application records. Student/personal dashboards include configured eligibility matches, saved opportunities, upcoming saved deadlines, application outcomes and support counts. No recommendation score or algorithmic personalisation is claimed.

## Opportunity catalogue and publication

The database-backed category catalogue starts with hackathons, competitions/innovation challenges, internships, scholarships, fellowships, grants, incubation, research, workshops/training and other approved opportunities. System Administrators can add, rename or deactivate categories. Historical listings retain their category relation.

Each listing supports its stable ULID identifier, title, category, summary, description, organiser, source attribution/URL, application instructions/method, written and structured eligibility, country/region, delivery mode, venue, opening/deadline and programme dates, places, award information, optional private cover, visibility, creator/approver metadata and publication history.

Chronological dates are validated. No-deadline listings explicitly say the deadline has not been announced. A missing amount or organiser detail is never replaced with a random business figure.

Publication follows:

```text
Draft / Returned → Submitted → Approved → Published → Closed → Archived
                         ↘ Returned / Rejected
Published → Approved (unpublish)
Approved / Rejected → Draft (revision)
```

Every publication decision requires a reason and records its actor/time. Editors save a draft, preview the detail page, then use separately authorised review/publication actions. Creation alone does not grant approval or publishing. A creator who independently holds the listing approval/publishing permissions may use those rights; application reviewers always have separate conflict-of-interest checks.

Owning workspace cannot be transferred. Institutional managers cannot create or move listings into another institution. Published listings must be unpublished and returned to draft before editing. Questions, structured eligibility, application method and group acceptance freeze when the first application draft exists, preserving question/document references.

Additional listing managers require active membership and pre-existing management authority in the same institution. Assignment gives listing context, not new institutional permissions. Assignments can be removed independently; creator and explicit wider institutional permissions remain governed by their own grants.

## Visibility and eligibility

Visibility supports:

- Platform: all active authorised Ideyra identities can discover published/closed platform listings.
- Institution: active authorised members of the owning institution.
- Restricted: authorised institution members matching a configured role, programme, year, intake, class or academic group audience.

Restricted audience targets use **OR** matching: at least one configured target must match. References are validated against the owning institution. System platform administration does not automatically expose institution-restricted listings or private sponsorship content.

Application eligibility is a separate check. Internal applicants must have an active Student membership in the selected active institution, application permission, current audience access, an open publication window and matching structured eligibility. Eligibility uses **AND between configured rule groups**, **OR within each group**.

The institution context must match an institutional listing. Platform listings can accept eligible students from their authorised institutional context. Written criteria that are not expressible through supported affiliation rules remain subject to organiser review; the system does not infer age, citizenship, grades or qualifications from free text.

Discovery supports category, country/region, delivery mode, deadline, institution, configured eligibility, open/closed and keyword filters. Sorting supports newest, approaching deadlines (unknown deadlines last) and alphabetical title. Listings, applications and support requests are paginated.

## Internal applications and consent

Internal applications support motivation, relevant skills, optional authorised project reference, an explicitly shared project summary, configured answers and private supporting documents.

Supported question types are text, textarea, number, yes/no, configured select and document. Up to twelve questions can be configured. Required responses/documents and supported response values are validated again on submission. Document labels identify their purpose; uploads require the applicant to attest a valid sharing basis.

A draft is distinct from submission. Reviewers cannot read another applicant's unsubmitted draft. Applications follow:

```text
Draft → Submitted → Under review / More information requested / Shortlisted
                                    → Accepted / Rejected
More information requested → corrected draft content → Submitted (new snapshot)
Permitted non-final states → Withdrawn
```

Only the applicant/representative edits or withdraws their own application. Submitted fields cannot be overwritten. Reviewers require listing management context and review permission; they cannot review their own application or a group application in which they participate. Review decisions require applicant-visible feedback. Internal notes are encrypted and never shown to applicants or exported.

Each submission creates an encrypted immutable snapshot of answers, question definitions, shared statements, project reference, participants and their academic context, consent evidence, document/version references and relevant opportunity instructions. Submitted files remain immutable. A revision retains prior snapshots/files; reviewers see the last submitted version until resubmission, and new unsubmitted revision files cannot be downloaded by reviewers.

One record per opportunity/person or opportunity/group is enforced by a database unique constraint and transactional duplicate checks. Additional-information resubmissions create new snapshots on that record. Withdrawal preserves history and does not create an automatic new application slot.

### Group applications

Only the current active academic group leader can act as representative. The listing must allow groups. The group, institution and optional linked group project must match the authorised context.

The representative selects current group members, and every participant must independently meet access/eligibility rules. Each selected member receives an authenticated consent request. Consent is recorded through CSRF-protected POST actions; the representative cannot consent for someone else.

Changing shared content or uploading another file resets consent. An unchanged save preserves consent and does not send another identical invitation. Invitation deduplication uses a per-application content/document signature. Submission rechecks current group authority, member list, eligibility and every participant's consent.

Participant names/IDs, relevant academic context and consent timestamps are preserved at submission. No private profile/contact fields are copied automatically. Later membership changes do not rewrite historical snapshots. Active former participants can read an already-submitted shared history where still authorised, but removal prevents access to a current unsubmitted group draft. Institution membership revocation removes future institution-controlled access.

## External applications

External application URLs require HTTPS and are configured by an authorised listing editor.

Opening the external link does not submit an Ideyra application or establish an external submission date/status. A user may explicitly save a self-report labelled **external_reported, unverified**. Its submitted_at remains null. It cannot enter the internal review workflow and is excluded from internal submission, acceptance and participation metrics.

Ideyra does not scrape, complete or submit an external website's forms.

## Bookmarks and deadlines

Bookmarks are unique per user/listing and can be removed. Saved lists and links always recheck current audience access. Dashboards show saved deadlines within the next seven days.

The tested command is:

```shell
php artisan ideyra:opportunities:remind --no-interaction
```

Laravel registers it hourly. It sends private database notices for bookmarked, currently available published listings closing within 72 hours. Closed, expired, unknown-deadline, inaccessible or inactive-user records are excluded. A unique user/listing/event ledger prevents repeat notices for the same deadline; a changed deadline permits a new notice.

**Automatic reminders are not claimed to be running on this host.** Laravel command registration and isolated command tests are verified. The host must separately run Laravel's scheduler every minute, for example through an appropriate Windows scheduled task invoking `php artisan schedule:run`. Phase 02's project reminder schedule is retained.

## Project sponsorship and commitments

This is explicitly a **non-payment** workflow. Types cover financial support pledges, equipment/materials, mentorship, technical assistance, workspace/facilities, training and other in-kind support.

An authorised owner/leader can prepare a request on a current project, recording description, justification, intended use, optional estimated value/currency and timeframe. Institutional request permission is explicit. Personal project ownership permits private drafting, but it never supplies staff approval or financial management authority.

Request states:

```text
Draft / Returned → Pending review → Approved for outreach → Closed
                                    ↘ Returned
Open request states → Cancelled
```

The requester cannot approve their own request. Institutional review/management requires current project access, active authorised staff membership and the relevant permission. Personal projects additionally require the staff member to be an explicitly assigned supervisor; unrelated institution staff and System Administrator status do not expose personal support data.

Sponsor contacts can be recorded only by authorised staff for approved outreach. Contact fields and verification evidence are encrypted. Verification records who checked the contact, when and on what basis. No sponsor login/account is created.

Commitment states are proposed → under discussion → committed → fulfilled, with permitted cancellation. Recording commitments and verifying fulfilment are separate capabilities. Fulfilment requires an explicit authorised staff decision, a verified sponsor contact and written evidence. Status decisions are audited. No pledge automatically becomes fulfilled or money received.

Project participants can see authorised support summaries. Private sponsor contact details, verification evidence and staff commitment event details require staff management authority.

No bank/card transfers, wallets, balances, receipts or disbursements exist in this phase.

## Permissions

Existing underscore-style keys are reused:

- opportunities.view, opportunities.create, opportunities.edit_own, opportunities.manage, opportunities.approve, opportunities.publish, opportunities.review_applications.
- New: opportunities.apply and opportunities.manage_all.
- New sponsorship keys: sponsorships.request, sponsorships.review, sponsorships.manage, sponsorships.record_commitment and sponsorships.verify_fulfilment.
- Existing projects.sponsor, projects.manage_sponsorship and projects.review_supported are recognised as request/manage/review equivalents. Explicit denial of a corresponding capability wins over an alternative grant.

Institution Administrators receive institutional opportunity/support defaults. Students receive opportunity viewing/application and sponsorship request defaults. Lecturers/mentors receive viewing; authoring, publication, review and sponsorship administration require explicit grants and assignment context. A project supervision role alone grants no sponsorship administration.

PhaseThreeSeeder records a one-time role upgrade marker, preserves existing membership overrides and later role customisations, and seeds only category/permission metadata. Re-running it does not create users, applications, sponsor contacts or financial records.

## Database and architecture

One additive migration creates 20 tables:

| Area | Tables |
| --- | --- |
| Catalogue | opportunity_categories, opportunities, opportunity_audiences, opportunity_managers, opportunity_questions, opportunity_decisions |
| Applications | opportunity_applications, opportunity_responses, opportunity_consents, application_snapshots, application_participants, application_documents, application_reviews |
| Saving/notices | opportunity_bookmarks, opportunity_notices |
| Support | sponsorship_requests, sponsorship_decisions, sponsor_contacts, sponsorship_commitments, sponsorship_commitment_events |

Eight new Eloquent models have factories: OpportunityCategory, Opportunity, OpportunityApplication, ApplicationDocument, ApplicationReview, SponsorshipRequest, SponsorContact and SponsorshipCommitment. Small pivot/history tables use the query builder, matching existing conventions.

Policies, Form Requests and workflow/access services enforce server-side authority. Foreign keys, unique constraints and parent/child checks protect ownership, question/application context and sponsor-contact/request pairing. Transactions and row locks protect submission versions and state decisions.

No Phase 02 tables or personal ownership records were replaced.

## Analytics and interpretation

Existing Chart.js charts, theme tokens, tooltips, keyboard inspection, accessible tables, PNG export and authorised CSV handling are reused.

System reporting includes catalogue/published/open opportunities, internal applications, review queues and participating institutions, with publication trend, category bar, application-status doughnut and submission trend.

Institution reporting includes institution-owned listings, listings currently available to its members, student participation, applications, shortlisted/accepted outcomes and authorised sponsorship requests. Its four opportunity charts are applications by programme, unique-student participation over time, available opportunity categories and sponsorship request status.

Personal reporting shows authorised discovery/application activity, saved opportunities, deadlines and support counts. Supervisors see related records only through existing project and application permissions.

Definitions:

- Creation cohorts use inclusive selected calendar dates; deadlines display the application timezone.
- Publication trend counts each listing at its most recent publication timestamp.
- Internal submission trends use each application's latest submitted_at, including resubmissions once.
- Individual and group applications each count as **one application record**.
- Unique participation counts distinct submitted participant user IDs across all selected applications, preventing a student from being counted twice because they applied individually and with a group.
- Programme charts count distinct applications per recorded primary programme. Group class programme takes priority; individual applications use the lowest assigned class ID recorded at submission.
- Student trend buckets deduplicate each user within the bucket. A student may appear in different periods.
- Acceptance rate is accepted applications / applications currently decided accepted or rejected. Pending, withdrawn and external reports are excluded from that denominator.
- Current-population institutional participation rate is distinct currently active students who participated in the selected cohort / currently active Student members. Historical participants remain in historical counts, but no longer-active students are excluded from this rate.
- Available-listing counts honour published/closed state and actual audience affiliation targets of active members with view authority; eligibility remains a separate check.
- Sponsorship charts count requests, not values or payments. Different currencies are never added into an invented financial total.

Dates are limited to three years; weeks start Monday. No fake data or recommendation score is used. Main dashboards add module charts when records exist and link to dedicated analytics, where zero-data states remain available.

CSV exports re-evaluate current authority/context, quote fields and neutralise spreadsheet formula prefixes. Application summary exports include only a fixed permitted field list, excluding private reviewer notes and documents.

## Notifications and privacy

Private database notifications cover publication (opt-in), application submission/review, requested information, shortlist/acceptance/rejection, group consent, saved deadlines, sponsorship decisions and commitment status changes.

Publication notices are deduplicated per eligible recipient/listing; deadline and consent events have separate deduplication keys. Application review fan-out is limited to candidate platform reviewers or institution staff with review grants, followed by current assignment/conflict checks.

Messages use generic notices rather than private applicant, project, sponsor or document contents. Links resolve through current policies rather than trusting stored notification IDs.

The existing mail configuration is unchanged. These events use database notifications; no external inbox delivery is claimed.

Files use private local storage and generated paths, with per-download parent/permission checks, safe display names, attachment delivery, nosniff and no-store headers. Supporting uploads are allowlisted documents/images up to 10 MB; covers are PNG/JPEG/WebP up to 4 MB with dimension limits. HTML, SVG and executable types are rejected. Private routes remain outside the PWA static asset cache.

Keep APP_KEY backed up: encrypted snapshots, notes, contacts and evidence depend on it.

## Verification

Tests use the existing guarded in-memory SQLite configuration. Browser tests use separate timestamped SQLite files and random ephemeral passwords; they do not reset the development database.

Coverage includes creation/publication, explicit permission denials, visibility vs eligibility, dates, filters, group authority/consent, invite deduplication, immutable submissions, revision-file privacy, supporting document security, self-review conflicts, withdrawals, truthful external reporting, sponsor request/review/verification, composite constraints, accurate scoped aggregations, CSV protection, category administration and removable listing managers.

The focused browser journeys cover publication through accepted application, private internal notes, document submission/resubmission, group consent, personal project support, confidential contacts, verified commitment fulfilment, mobile layouts, themes, charts and actual CSV downloads.

Final verification results:

| Check | Result |
| --- | --- |
| Full PHPUnit suite | 134 passed, 1,132 assertions |
| Full Playwright regression | 23 passed, including all three Phase 03 journeys |
| Final analytics checks | 2 passed, 12 assertions |
| Production Vite build | Passed |
| Laravel Pint | Applied successfully |
| Blade view compilation | Passed |
| Git whitespace check | Passed |
| Existing MySQL database upgrade | Phase 03 migration and permission/category seeder applied successfully |

The new browser journeys completed without JavaScript console errors and checked light/dark themes and layouts from 360 px to 1,440 px. Existing login, academic administration, dashboard and project browser regressions also passed. A private database backup was taken before the additive migration; existing data and `.env` were preserved.

## Operation and known limits

- The host scheduler is not configured by this phase; automatic reminders require the documented host setup.
- Upload allowlisting/private authorisation are implemented. Antivirus/content scanning and institutional storage quotas are not integrated.
- Supporting documents are retained with immutable submission history. A broader retention/deletion policy requires a separate product decision.
- External URL authenticity and written eligibility criteria require authorised organiser/reviewer judgement. The system validates URLs and structured affiliation rules, not third-party claims.
- A withdrawn application retains its single opportunity/person or opportunity/group slot; there is no automatic fresh application after withdrawal.
- Publication notices run in checked batches; very large audiences would benefit from queued fan-out with monitored workers.
- No real-money or automated external application functionality is implemented.

## Phase 04 recommendations

Build CV/profile and controlled portfolio publication around explicit consent and separate publication states. Add upload scanning, retention/storage quotas, monitored notification workers and verified scheduler operation as production deployment capabilities grow. Consider richer eligibility criteria only with explicit validated fields and explainable rules, rather than invented matching scores.

