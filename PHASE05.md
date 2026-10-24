# IDEYRA Phase 05 implementation report

## 1. Architecture and compatibility

Implemented on the existing Laravel 13.38 / PHP 8.3 / Blade application. No dependencies were added. Existing Montserrat, Lucide assets, workspace components, Chart.js 4.6.1 and Phase 01–04 authorisation and workflows are reused.

AiProviderContract separates transport from the application. AiProvider supplies the initial JSON chat-completions adapter; AiContext selects authorised fields; AiWorkflow handles availability, reservations, prompts and validated responses. AiConversationPolicy protects private content. AiMessageRequest validates requests; AiController exposes authenticated interaction, explicit review actions and metadata reporting.

## 2. Provider integration

The initial adapter supports an explicitly configured OpenAI-compatible HTTPS chat-completions endpoint, JSON mode, bounded input/output, a 30-second default timeout, disabled redirects and zero automatic retries. Provider names other than the registered adapter fail closed. Additional providers implement the contract and require an explicit binding; there is no silent fallback.

API structure was checked against [official OpenAI structured-output documentation](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=chat). JSON mode is followed by server-side schema and content validation. Unsupported model parameters, refusals, incomplete output, rate limits, connection errors and malformed output fail safely.

**Live status:** the local readiness command reports that AI is unavailable. No live provider request was made. Live connectivity, model support, provider retention terms and production quality remain unverified.

## 3. Features delivered

- Idea Coach: Explore, Challenge, Refine and Plan modes; structured questions, assumptions, risks, alternatives and a next action.
- Project Mentor: opt-in brief, schedule, aggregate progress, task, milestone and deliverable context; reviewed task and milestone creation.
- Career Explorer: individually selected professional records and shareable project showcases; learning goals, career directions and requirements-based reflection.
- CV Assistant: one selected section, original/revision/explanation comparison, conservative fact-preserving validation and explicit acceptance/editing through the existing immutable CV version workflow.
- Opportunity Explorer: server-side visibility, current window, student membership and structured eligibility checks before provider use; optional explanations based on bounded eligible context.
- Supervision Assistant: current assigned-supervisor access, selected project progress and reviewed student-visible feedback through the established workflow.

No feature submits applications, approves projects/deliverables, assigns grades, changes ownership, grants permissions or publishes content autonomously.

## 4. Database and models

One additive migration creates ai_settings, ai_conversations and ai_usages, represented by AiSetting, AiConversation and AiUsage. Settings contain only non-secret controls. Conversations contain encrypted message and insight arrays, feature, owner, institution, related-record identifier, consent version/date and expiry. Usage rows contain request status, feature, owner/workspace, configured provider/model, reported token counts and optional configured-cost estimates.

Consent and private drafts are embedded in their conversation, avoiding unnecessary parallel tables. Usage reservations are inserted before calls; business actions and audit metadata use existing infrastructure. Related records are polymorphic identifiers and are reauthorised through their original module on every use.

## 5. Permissions and consent

AI routes require authentication and active accounts. Existing idea/project ownership, memberships, assignments, supervisor restrictions and career sharing approval remain authoritative.

System Administrators manage platform access, feature ceilings, institution allowlists and quotas. Institution Administrators with their existing institution-profile permission manage only their own narrower settings. This phase reuses existing administration permissions rather than introducing automatic role grants.

Every interaction requires explicit consent for its selected context. Server configuration alone never grants data-sharing consent. Each institution starts denied until enabled by the platform. Private AI conversation policies have no System Administrator or Institution Administrator bypass.

## 6. Storage and privacy

Messages and saved insights are encrypted using Laravel application encryption. Conversations expire 90 days after creation and may be deleted immediately by their owner. Expired content becomes inaccessible even before scheduled deletion. The daily ideyra:ai:purge command deletes expired conversations/insights and metadata older than 365 days. Deleting a conversation nulls its metadata reference.

Run Laravel's scheduler in the deployment environment to enforce physical deletion. Provider-side retention and existing database-backup retention require operational policy; application deletion cannot delete copies already held by a provider or historical backups.

No attachment, private supervision note, login email, whole profile or entire project is automatically sent. Professional verification labels require current matching approved content and an active verifying institution. Operational analytics exclude raw prompts/responses. Error redirects do not flash private questions into sessions/HTML.

## 7. Quotas and reliability

Default daily ceilings: user 20, institution 500, platform 2,000. Platform and institution controls can reduce the relevant ceiling. Failures count as attempts. Shared cache locks protect atomic quota reservations and prevent simultaneous requests by a user. Production deployments need a shared lock-capable cache and a correctly running scheduler.

Input is bounded to 12,000 bytes; model output defaults to 1,200 tokens and has a separate response byte cap. Provider retries are manual to avoid duplicate charges. Token values are used only when reported, never invented. Cost remains unknown unless reliable prices are explicitly configured for the selected model.

The browser preserves the question for retry on failures and supports stopping the wait. Stopping the wait does **not** guarantee provider cancellation; a request already sent may complete and consume quota.

## 8. Analytics and controls

AI Management and institution settings link to real-data AI analytics. Existing administration dashboards link to their corresponding AI reporting scope. Personal, institution and platform views provide filters, daily quota progress, usage over time, feature bars and outcome doughnuts; platform reports add institution bars. KPIs include requests, distinct users/institutions, reported tokens, failures, failure rate and enabled features.

Existing Chart.js theme, reduced-motion handling, keyboard interaction, accessible data tables, PNG export and authorised CSV exports are reused. Empty periods remain empty. CSV labels are protected against formula prefixes. No conversation contents are included.

## 9. Security controls

All provider content is untrusted and escaped by Blade. JSON keys/types/lengths are validated; no output can directly execute SQL or tools. The provider receives fixed instructions and a separate JSON data message. No browsing, file ingestion or autonomous tool execution exists.

Explicit insight/idea/task/milestone/CV/feedback actions require confirmation, current server authorisation and an allowlisted action. Repeated saves of the same suggestion/action are rejected. CV acceptance checks that the original section has not changed. Credentials are server-only, configuration errors are sanitised and configured keys reflected in output are rejected.

Private responses have no-store/noindex headers and workspace robots metadata. The existing PWA static-asset-only caching boundary is preserved.

## 10. Verification

Automated feature coverage includes configuration, consent, encrypted private history, administrator exclusion, record/workspace access, selected-context isolation, stale verification, provider failures/timeouts/partial output/invalid JSON, quotas/concurrency, XSS, injection boundaries, explicit actions, CV integrity/versioning, opportunity filtering, exact aggregation/date filters, authorised exports and retention.

Browser coverage includes responsive light/dark AI screens, private insights, administration reporting, chart rendering, truthful unavailable states, retry/cancel controls and existing Phase 01–04 workflows. Test providers and conversation/usage fixtures exist only in isolated automated tests. No demo AI records were created in the development database.

Verification passed: full PHP regression suite, **189 tests / 1,618 assertions**; full browser regression suite, **28 scenarios**, followed by **three final AI browser scenarios** covering populated line/bar/doughnut charts, mobile/dark layout, explicit insight review and retry/stop controls. The final AI-specific PHP checks include the additional token-accounting assertions. Production Vite build, Pint, Blade compilation and git diff whitespace checks passed. Ignored storage/app/phase05-* files contain the detailed test/build outputs and visual review captures.

## 11. Build and operational changes

Production assets were built with the existing Vite installation. Pint and Blade compilation are checked. The additive migration was applied after a private, git-ignored database backup. Existing records were retained and .env was not modified. No administrator bootstrap or user seeder was run as part of this phase.

## 12. Known limitations

- Live AI is intentionally unavailable until an approved endpoint, model, key and enable flag are configured.
- Output is advisory and cannot guarantee factual correctness. Human fact checking remains necessary.
- CV validation is deliberately conservative and can reject legitimate new wording; users may edit manually.
- Conversations retain history for reference but do not automatically resend past messages. Each question uses the context selected for that interaction.
- Opportunity discovery bounds the visible candidate batch to 30 and provider context to six eligible summaries; use the existing Opportunities Hub for complete discovery and authoritative requirements. Structured-rule failures are not overridden by AI. Written criteria remain unverified.
- No external browsing, uploaded-document analysis, autonomous agents, asynchronous job processing, grading or automatic publication is included.
- AI activity is reported in dedicated, linked dashboard sections rather than adding excessive chart density to the main administration landing pages.
- Notifications are not emitted for ordinary synchronous chats. Existing reviewed task/feedback workflows retain their own useful notifications.

## 13. Required live configuration

Set IDEYRA_AI_ENABLED, IDEYRA_AI_PROVIDER, IDEYRA_AI_MODEL, IDEYRA_AI_ENDPOINT and IDEYRA_AI_API_KEY locally using the safe .env.example names. Keep secrets outside source control. Choose a provider/model supporting the adapter's JSON chat-completions parameters and review institutional/provider privacy terms.

Run php artisan config:clear after local environment changes. For a deployment using cached configuration, rebuild it with php artisan config:cache after verifying configuration. Restart any long-lived workers. Run php artisan ideyra:ai:status to inspect readiness without displaying credentials.

Enable intended institution contexts and feature/quota ceilings through AI Management. Confirm applicable institutional consent policy, deploy shared locks and the scheduler, then perform a small explicit authorised live test. A configured status is not proof of connectivity.

## 14. Phase 06 recommendations

Evaluate responses against institution-approved factuality and academic-integrity examples; tune prompts and conservative CV rules from those results. Consider provider-specific strict JSON-schema adapters, model-specific pricing configuration, institution-governed retention and optional cancellable asynchronous requests if real usage warrants them.

