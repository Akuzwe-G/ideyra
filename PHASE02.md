# Ideyra Phase 02

Phase 02 adds Idea Vault, academic project groups and Project Studio to the existing Laravel application. It uses the existing identity, membership, permission, notification, chart, theme and navigation systems. No packages were added.

## Implemented journey

- **Idea Vault**: private drafts and developing ideas, category and tags, selected collaborators by exact account email, institutional visibility, assigned review and wider review queues, feedback history, archive and controlled project conversion.
- **Review copies**: an owner explicitly consents to creating one institutional copy per institution. The original personal idea retains its ownership, visibility and content. Submitting an institutional idea also requires explicit sharing consent.
- **Academic groups**: institution, class and its programme/intake/year, draft/active/completed/archived states, student members, a selected leader, multiple supervisors and a narrow list of delegated leader permissions. Members must be active students assigned to the chosen class.
- **Project Studio**: personal or institutional ownership, optional approved institutional idea origin, optional academic group/class, brief, objectives, outcomes, dates, team assignments, approval/return/rejection decisions, lifecycle and archival history.
- **Work planning**: ordered milestones, tasks, priorities, assignees, due dates, derived overdue indicators, task discussion, list and Kanban views, and keyboard-operable status controls.
- **Deliverables**: assigned submission responsibility, private attachments or text, immutable numbered versions, independent review, changes requested, resubmission and approval. One attachment is supported per version.
- **Supervision**: explicitly assigned project access, student-visible feedback, confidential encrypted notes, linked deliverable feedback and workload summaries.
- **Search**: ideas by title/category/tag/status; groups and projects by title/status/class/programme/year; tasks by title/status/priority/assignee/due date; deliverables by title/status/assignee/due date. Lists are paginated. The Kanban board displays the current filtered page of up to 25 tasks.

Start from the sidebar's Idea Vault, Academic groups, Project Studio, My work and Supervision links.

## Ownership and permissions

The existing underscore permission naming convention is retained. There are no duplicate hyphenated variants.

Implemented groups of permissions:

| Area | Keys |
| --- | --- |
| Ideas | `ideas.create`, `ideas.view_own`, `ideas.edit_own`, `ideas.share`, `ideas.view_shared`, `ideas.submit_for_review`, `ideas.review`, `ideas.review_all` |
| Groups | `groups.create`, `groups.view_assigned`, `groups.view_all`, `groups.manage_assigned`, `groups.manage_all`, `groups.assign_members`, `groups.assign_leaders`, `groups.assign_supervisors` |
| Projects | Existing `projects.create`, `projects.create_institutional`, `projects.view_assigned`, `projects.edit_own`, `projects.manage_assigned`, `projects.review`, `projects.approve`; plus `projects.view_all`, `projects.supervise`, `projects.archive` |
| Tasks | `tasks.create`, `tasks.assign`, `tasks.update_assigned` |
| Deliverables | `deliverables.create`, `deliverables.submit`, `deliverables.review`, `deliverables.review_all`, `deliverables.approve` |

The existing `academic.supervise_projects` and `academic.review_work` keys are recognised alongside project supervision and deliverable review. Explicit membership denials on either corresponding key win.

Personal drafting and project ownership are intrinsic to an active identity. Institutional project creation requires **both** `projects.create` and `projects.create_institutional`. The latter is an explicit institutional opt-in for students and staff; it is not enabled for them by the new default permissions.

The PhaseTwoPermissionSeeder merges new role defaults once, records `phase02_permissions_initialized`, and preserves existing role configuration and membership overrides on subsequent runs. Institution administrators receive institutional management defaults. Lecturers and mentors receive assigned supervision and review defaults; their membership and assignments still restrict access.

Group leadership is a contextual assignment. The only delegable keys are group management/member assignment, assigned project management and task creation/assignment. A leader cannot delegate powers they do not hold, change institutional settings or bypass explicit denials. A personal project leader does not become its owner.

System Administrator status does not bypass private idea/project policies or automatically reveal institutional business content. Institutional content requires an active membership and relevant permissions; broader access requires explicit wider-scope permission. Platform identity/administration continues to work as before.

## Review and lifecycle policy

Institutional ideas may be reviewed by their assigned reviewer or a member with `ideas.review_all`, together with `ideas.review`. Owners cannot review their own ideas. Institutional idea conversion requires approval and retains the institution. Each idea has at most one linked project.

Institutional projects begin as drafts and enter pending approval. An independent authorised institutional reviewer approves, returns for revision or rejects with a reason. Returning retains the decision history. Personal projects can move directly from draft to in progress.

Work progress does not automatically close a project. Final completion requires a reason, every milestone completed, every task completed and every deliverable independently approved. Institutional completion requires an independent institutional approval authority. Completed/cancelled/archived projects reject further work edits; completed and cancelled projects can be archived.

An empty project has 0% progress. The formula is:

```text
round(100 * (completed tasks + approved deliverables)
          / (all tasks + all deliverables))
```

Each work item has equal weight. Milestones are checkpoints and a completion gate, rather than a second contribution to the percentage. A personal owner who submits their own deliverable must assign an independent supervisor to approve it.

## Data model and migration

The additive workspace migration creates 19 tables:

- ideas, idea_collaborators, idea_reviews;
- project_groups, group_members, group_supervisors;
- projects, project_members, project_decisions;
- milestones, project_tasks, task_assignees, task_comments;
- deliverables, submissions, deliverable_reviews;
- supervision_notes, project_activities, task_reminders.

Twelve Eloquent models and matching factories support the main entities. Small pivot/history tables use the query builder. Public-facing identifiers use unique ULIDs; route binding is still authorised server-side.

Composite foreign keys enforce group/membership institution consistency and child/project consistency. Unique constraints prevent duplicate project origins, duplicate review copies per institution, duplicate assignments, duplicate submission versions and duplicate review/reminder records.

A second additive migration allows text-only submissions by making attachment metadata and body nullable. At least one of body/file is required by validation. Its rollback deliberately leaves nullable columns in place to preserve valid text-only history.

Both migrations were applied to the existing local database. A private SQL backup was taken under ignored storage before the first migration. Existing data was not reset and .env was not overwritten. No sample business records are seeded into the real database.

## Security controls

All module routes require authentication and the existing active-account middleware. Policies and scoped queries enforce ownership, active institutional context, assignments and current permissions. Cross-parent child IDs are rejected before reading or mutating their content.

Group participants are resolved from current membership rather than copied permanently into projects. Removing a direct or group assignment removes its access unless another authorised assignment remains. Notification links recheck current access.

Task assignees can update only assigned progress and comments. Changing planning fields or assignment lists requires project management and the relevant capability. Only managers can reopen completed tasks.

Submissions are stored on Laravel's **local private disk**, outside public storage, with generated paths. Downloads require current project access and matching parent/project IDs, force attachment delivery, set nosniff and disable caching. Files are limited to 10 MB and a document/image/archive allowlist; HTML, SVG and executable uploads are rejected. Archives are stored without extraction or execution.

Versions cannot be edited in place. A deliverable must receive a changes-requested decision before a new version is accepted. Row locking serialises version creation and reviews. Reviewers cannot approve their own submissions.

Confidential supervision notes and activity metadata are encrypted using Laravel model casts. Internal notes are visible only to assigned supervisors, including when an institution administrator or project owner can otherwise view the project. Internal notes generate no student activity or notifications. Back up and retain APP_KEY to preserve encrypted history.

## Analytics

The existing Chart.js integration provides theme-aware responsive charts, tooltips, keyboard inspection, accessible tables, real empty states, PNG export and authorised CSV export.

Institution administrators see total/active/completed projects, approval queues, overdue tasks, pending submission reviews, project status doughnut, programme/year bars and a completion trend. Personal dashboards show scoped ideas/projects/groups and recent progress. Supervision adds assigned projects and review workload.

Status and academic grouping charts use the selected project **creation cohort**. Completion trends use actual completion timestamps, including projects created earlier and subsequently archived. Weeks begin Monday. Date filters cover inclusive calendar dates and are limited to three years. Counts are scoped to the viewer before aggregation; unassigned projects and other institutions do not contribute.

CSV exports recalculate permissions and neutralise spreadsheet formula prefixes. PNG/table/CSV use the same chart series. No random or illustrative data is used.

## Notifications and operation

Database notifications cover group membership, task assignment, task deadlines, idea feedback, project decisions, deliverable submissions/reviews and supervisor assignments. Messages contain generic workspace notices rather than private project titles or submission text. Repeat unchanged assignments do not send another assignment notice.

Run task reminders manually with:

```shell
php artisan ideyra:projects:remind --no-interaction
```

Laravel schedules this daily at 08:00 in the application timezone. The host must run Laravel's scheduler every minute (`php artisan schedule:run`, or an equivalent supervised scheduler). A unique task/user/due-date ledger prevents repeated reminders; changing a task's due date permits a new reminder. Completed work, closed projects and users without current access are excluded.

The existing development mailer is unchanged. These events use database notifications; no claim is made that an email was delivered to an external inbox.

For another installation, apply migrations and seed the explicit permission upgrade:

```shell
php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --class=PhaseTwoPermissionSeeder --force --no-interaction
npm.cmd run build
```

These commands do not create administrator accounts. Bootstrap credentials and account management remain in the existing dedicated local bootstrap flow.

## Validation

PHPUnit runs only against uncached in-memory SQLite, with a bootstrap guard that refuses any other connection before a database refresh. Browser tests use separate timestamped SQLite files and random ephemeral passwords, never the development database.

The tests cover private content, selected sharing consent, review copies, independent reviews, conversion duplicates, immutable ownership, institutional creation opt-in, group context/leadership, explicit denials, scoped assignment/progress, child isolation, private versions/downloads, upload rejection, encrypted notes, completion gates, reminder deduplication, scoped chart dates/CSV, filters, stale notification links and database constraints. Existing authentication, administration, academic, application, contact, notification, chart and PWA regressions are retained.

Final verification on 24 October 2026:

| Check | Result |
| --- | --- |
| Full PHPUnit suite | 107 passed, 925 assertions |
| Full Playwright suite | 20 passed, including both Phase 02 journeys and existing regressions |
| Production frontend build | Passed |
| Laravel Pint | Applied successfully |
| Blade view compilation | Passed |
| Git whitespace/error check | Passed |
| MySQL migration status | Both Phase 02 migrations applied |
| Scheduled reminder registration | Daily 08:00 command registered |

Browser checks cover project tabs at 360, 390, 768 and 1440 pixels, task updates and Kanban, scoped institutional group/project creation, chart keyboard interaction, themes and actual CSV downloads. The new journeys and existing analytics report no JavaScript console errors. The login viewport, sidebar, confirmation, profile, notification, academic administration, application/PDF, public website and PWA regressions also pass.

The browser run identified and resolved a shared form-action DOM shadowing error and a missing academic-class institution relationship. Regression coverage now includes populated institutional creation forms, empty project tabs and explicit idea visibility denials during mutation/review. Test output and screenshots are retained under ignored private storage.

## Remaining operational limits and Phase 03

- File uploads use an allowlist and private storage. Antivirus/content scanning is not integrated.
- Kanban uses accessible status controls rather than drag-and-drop. A board page is capped at 25 filtered tasks.
- The host scheduler must be configured to run scheduled reminders.
- No public projects, portfolios, opportunity applications, CV generation, sponsorship payments or AI recommendations were introduced.
- Phase 03 can build opportunity/project linking and portfolio publication around explicit consent, separate publication states and reviewed data scopes. Introduce upload scanning and production queue/notification delivery as deployment requirements grow.

