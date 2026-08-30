# Coverage Report — pipelinq

Generated: 2026-05-24 00:00 UTC
Branch: feat/features-tab-wiring
Scanner: opsx-coverage-scan v1
Scope: PHP only (lib/). Vue/JS source under src/ deferred to a future scan.

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 44 | — (already tagged) |
| plumbing | 31 | — (never tagged) |
| 1 — REQ matched | 130 | `/opsx-annotate pipelinq` |
| 2a — existing capability, no REQ | 10 (2 clusters) | `/opsx-reverse-spec pipelinq --extend activity-timeline` (low priority) |
| 2b — no capability owner | 0 (0 clusters) | — |
| 3a — REQ broken (code removed) | 3 | Separate fix PR / triage |
| 3b — REQ never implemented | 80+ | Mark deferred, archive draft specs, or schedule |
| 4 — ADR conformance | annotation gaps in ~70 files | Bulk follow-up via `/opsx-annotate` |

**Inventory:** ~563 REQs across 37 specs; 99 PHP files in lib/ (Db/ + Migration/ excluded as instructed).

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-pipelinq`)

Grouped by capability → owning file. High-confidence matches first.

### capability: admin-settings

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Settings/AdminSettings.php | getForm() | REQ-AS-010 | 0.92 | Nextcloud admin-panel registration target |
| lib/Service/SettingsService.php | getSettings/updateSettings | REQ-AS-080 | 0.88 | settings-persistence path |
| lib/Service/SettingsService.php | createDefaultPipelines | REQ-AS-070 | 0.92 | REQ name 1:1 |
| lib/Service/SettingsService.php | createDefaultQueues | REQ-AS-090 | 0.85 | Queue Management Section default seeding |
| lib/Service/SettingsService.php | getUserSettings/updateUserSettings | REQ-AS-080 | 0.75 | user-scoped persistence (NEEDS-REVIEW) |
| lib/Service/DefaultPipelineService.php | createDefaultPipelines | REQ-AS-070 | 0.92 | default-pipelines on install |
| lib/Controller/SettingsController.php | index/create | REQ-AS-080 | 0.85 | settings persistence API |
| lib/Controller/SettingsController.php | reimport | REQ-AS-015 | 0.95 | REQ name 1:1 |
| lib/Controller/SettingsController.php | getUserSettings/updateUserSettings | REQ-AS-080 | 0.78 | user-scoped (NEEDS-REVIEW) |
| lib/Controller/LeadSourceController.php | index/create/update/destroy | REQ-AS-050 | 0.92 | Lead Source Configuration |
| lib/Controller/RequestChannelController.php | index/create/update/destroy | REQ-AS-060 | 0.92 | Request Channel Configuration |
| lib/Service/SystemTagService.php | <all> | REQ-AS-080 | 0.70 | system-tag CRUD (NEEDS-REVIEW; no dedicated REQ) |
| lib/Service/SystemTagCrudService.php | <all> | REQ-AS-080 | 0.70 | system-tag CRUD (NEEDS-REVIEW) |

### capability: openregister-integration

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Repair/InitializeSettings.php | run | Auto-Configuration on Install (Repair Step) | 0.95 | REQ explicitly names Repair Step |
| lib/Service/SettingsLoadService.php | loadSettings | Register Configuration File | 0.85 | config-file load |
| lib/Service/SettingsMapBuilder.php | <all> | Schema-to-IAppConfig Mapping | 0.92 | REQ name 1:1 |
| lib/Service/SchemaMapService.php | <all> | Schema-to-IAppConfig Mapping | 0.88 | schema-id-to-entity-type resolver |
| lib/Service/ConfigFileLoaderService.php | <all> | Register Configuration File Format Compliance | 0.92 | REQ name 1:1 |
| lib/Service/SettingsService.php | loadSettings | Register Configuration File | 0.82 | overlaps admin-settings (NEEDS-REVIEW) |

### capability: activity-timeline

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ActivityTimelineController.php | getTimeline | Timeline entries MUST be available via API | 0.95 | direct API entry-point |
| lib/Controller/ActivityTimelineController.php | getWorklog/createWorklog | Timeline MUST support manual entries | 0.92 | worklog = manual entry |
| lib/Service/ActivityTimelineService.php | getTimeline | Every entity MUST have a timeline view | 0.92 | REQ-named method |
| lib/Service/ActivityTimelineService.php | createWorklog/getWorklog | Timeline MUST support manual entries | 0.92 | manual-entry path |
| lib/Service/ActivityTimelineService.php | resolveEntityQueryParams | Every entity MUST have a timeline view | 0.80 | entity-resolution path |
| lib/Service/ActivityTimelineService.php | normalizeActivity | Activity events MUST cover all entity types | 0.82 | cross-entity normaliser |
| lib/Service/ActivityService.php | publishCreated/publishAssigned/publishStageChanged/publishStatusChanged/publishNoteAdded | Timeline MUST capture all interaction types | 0.85 | activity-stream publishers |

### capability: notifications-activity

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/NotificationService.php | notifyAssignment/notifyTaskCompleted/notifyTaskReassigned/notifyTaskExpired/notifyStageChange/notifyStatusChange/notifyNoteAdded | CRM Notifications | 0.88 | notify* pattern |
| lib/Service/NotificationService.php | notifyDealWon | Deal Won Notification | 0.95 | REQ name 1:1 |
| lib/Service/NotificationService.php | notifyDealLost | CRM Notifications | 0.78 | no Deal-Lost REQ (NEEDS-REVIEW) |
| lib/Service/ActivityService.php | publishDealWon | Deal Won Notification | 0.88 | Deal-Won REQ named |
| lib/Service/ActivityService.php | publishDealLost | Timeline MUST capture all interaction types | 0.78 | (NEEDS-REVIEW) |
| lib/Notification/Notifier.php | prepare | Notification Rendering | 0.92 | INotifier::prepare |
| lib/Activity/Provider.php | parse | CRM Activity Stream | 0.92 | IProvider::parse |
| lib/Activity/ProviderSubjectHandler.php | applySubjectText | CRM Activity Stream | 0.82 | subject rendering |

### capability: callback-management / task-background-jobs

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/CallbackService.php | <all> | Callback Service | 0.95 | REQ name explicitly matches class |
| lib/Service/TaskService.php | getDefaultDeadline/calculateDeadline/validateTask | Task Status Lifecycle / Create Terugbelverzoek | 0.72–0.75 | deadline + validation helpers (NEEDS-REVIEW) |
| lib/Service/TaskService.php | isDeadlineApproaching | Deadline Escalation Notifications | 0.92 | used by escalation job |
| lib/Service/TaskService.php | isDeadlinePassed | Task Expiry Background Job | 0.92 | used by expiry job |
| lib/BackgroundJob/TaskExpiryJob.php | run | Task Expiry Background Job | 0.95 | REQ name 1:1 |
| lib/BackgroundJob/TaskEscalationJob.php | run | Deadline Escalation Notifications | 0.95 | REQ name 1:1 |

### capability: contacts-sync

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ContactSyncController.php | search/import | Import from Contacts | 0.88–0.95 | REQ name 1:1 |
| lib/Controller/ContactSyncController.php | writeBack | Write-Back Sync | 0.95 | REQ name 1:1 |
| lib/Service/ContactSyncService.php | searchContacts/importContact | Import from Contacts | 0.88–0.95 | REQ name 1:1 |
| lib/Service/ContactSyncService.php | syncToContacts | Write-Back Sync | 0.92 | write-back path |
| lib/Service/ContactVcardService.php | syncToContacts | vCard Field Mapping Completeness | 0.85 | vCard mapping in sync |
| lib/Service/ContactVcardWriterService.php | writeToAddressBook | Write-Back Sync | 0.92 | vCard write-back |
| lib/Service/ContactImportService.php | importAsClient/importAsContact | Import from Contacts | 0.92 | import path |
| lib/Service/ContactDataBuilder.php | buildClientImportData/buildContactImportData | Import from Contacts | 0.85 | import-data builder |
| lib/Service/ContactVcardPropertyBuilder.php | buildProperties | vCard Field Mapping Completeness | 0.85 | vCard property builder |
| lib/Service/ContactLinkedUidsService.php | getLinkedContactsUids | Sync Status Indicator | 0.78 | sync-status (NEEDS-REVIEW) |

### capability: entity-notes

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/NotesController.php | list/create/deleteAll/deleteSingle | Notes CRUD on All Entity Types | 0.85–0.92 | REQ name maps |
| lib/Service/NotesService.php | getNotes/addNote/deleteNote/deleteAllNotes | Notes CRUD on All Entity Types | 0.92 | REQ name maps; NC Comments API |
| lib/Service/NoteEventService.php | triggerNoteEvents | Note Activity in Nextcloud Timeline | 0.88 | note-event → timeline |

### capability: kennisbank

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/PublicKennisbankController.php | index/show | Public vs Internal Articles | 0.88 | public endpoints |
| lib/Controller/KennisbankController.php | publicIndex/publicShow | Article Management | 0.85 | article listing/retrieval |
| lib/Controller/KennisbankController.php | submitFeedback | Agent Feedback | 0.95 | REQ name 1:1 |
| lib/Service/KennisbankService.php | getPublicArticles/stripInternalFields | Public vs Internal Articles | 0.92 | public/internal split |
| lib/Service/KennisbankService.php | validateFeedback/buildFeedbackData/calculateUsefulnessScore | Agent Feedback | 0.88–0.92 | feedback flow |
| lib/BackgroundJob/KennisbankReviewJob.php | run | Article Lifecycle Notifications | 0.85 | review job |

### capability: prospect-discovery

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ProspectController.php | createLead | Prospect-to-Lead Conversion | 0.95 | REQ name 1:1 |
| lib/Controller/ProspectController.php | index | Prospect Dashboard Widget | 0.78 | (NEEDS-REVIEW) |
| lib/Controller/ProspectSettingsController.php | index/update | Ideal Customer Profile Configuration | 0.88 | ICP settings |
| lib/Service/IcpConfigService.php | <all> | Ideal Customer Profile Configuration | 0.92 | ICP service |
| lib/Service/IcpConfigReader.php | <all> | Ideal Customer Profile Configuration | 0.85 | ICP reader |
| lib/Service/KvkApiClient.php | search | KVK API Integration | 0.95 | REQ name 1:1 |
| lib/Service/KvkResultMapper.php | <all> | KVK API Integration | 0.88 | KVK mapping |
| lib/Service/OpenCorporatesApiClient.php | search | OpenCorporates Integration | 0.95 | REQ name 1:1 |
| lib/Service/OpenCorporatesResultMapper.php | <all> | OpenCorporates Integration | 0.88 | OC mapping |
| lib/Service/ProspectScoringService.php | score/scoreAll | Prospect Fit Scoring | 0.92–0.95 | REQ name 1:1 |
| lib/Service/ProspectDiscoveryService.php | <all> | Existing Client Exclusion | 0.78 | (NEEDS-REVIEW; orchestrator spans REQs) |

### capability: contactmomenten / contactmomenten-rapportage / klachtenregistratie

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ContactmomentController.php | destroy | Contactmoment Update and Deletion | 0.95 | REQ name 1:1 |
| lib/Service/ContactmomentService.php | delete | Contactmoment Update and Deletion | 0.92 | delete path |
| lib/Service/ContactmomentService.php | getConfig | ContactmomentService Backend | 0.85 | backend service |
| lib/Controller/ReportingController.php | getSla/updateSla | SLA Configuration | 0.92 | SLA endpoints |
| lib/Controller/ReportingController.php | exportCsv | Export and BI Integration | 0.85 | CSV export |
| lib/Service/ReportingService.php | calculateFcr/calculateAverageHandlingTime | KPI Dashboard | 0.82 | KPI calcs |
| lib/Service/ReportingService.php | calculateSlaCompliance/getSlaTarget/setSlaTarget/getAllSlaTargets | SLA Configuration | 0.82–0.88 | SLA calcs |
| lib/Service/ReportingService.php | generateCsv | Export and BI Integration | 0.82 | CSV generator |
| lib/Service/ComplaintSlaService.php | <all> | REQ-KL-009 | 0.95 | Backend SLA Deadline Service |
| lib/BackgroundJob/ComplaintSlaJob.php | run | REQ-KL-010 | 0.95 | SLA Monitoring background job |

### capability: prometheus-metrics

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/MetricsController.php | index | Prometheus Metrics Endpoint | 0.95 | REQ name 1:1 |
| lib/Controller/MetricsController.php | collectMetrics | CRM Entity Count Metrics | 0.82 | metrics aggregator |
| lib/Controller/HealthController.php | index/checkDatabase/checkFilesystem | Health Check Endpoint | 0.88–0.95 | REQ name 1:1 |
| lib/Service/MetricsFormatter.php | formatAppInfo | Standard Application Info Metrics | 0.95 | REQ name 1:1 |
| lib/Service/MetricsFormatter.php | formatLeadCounts/formatRequestCounts | CRM Entity Count Metrics | 0.92 | count metrics |
| lib/Service/MetricsFormatter.php | formatLeadValues | Pipeline Value Metrics | 0.92 | value metric |
| lib/Service/MetricsFormatter.php | formatGauge | Metrics Export Format Compliance | 0.82 | OpenMetrics gauge |
| lib/Service/MetricsRepository.php | getLeadCounts/getRequestCounts/countObjectsBySchemaPattern | CRM Entity Count Metrics | 0.85–0.92 | count queries |
| lib/Service/MetricsRepository.php | getLeadValueByPipeline | Pipeline Value Metrics | 0.92 | value query |

### capability: queue-management

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/DefaultQueueService.php | createDefaultQueues | Default Queues | 0.92 | REQ name 1:1 |
| lib/Service/DefaultQueueService.php | createDefaultSkills | Default Skills (skill-routing) | 0.90 | REQ name 1:1 |
| lib/Service/QueueService.php | assignToQueue/removeFromQueue | Queue Item Membership | 0.92 | membership |
| lib/Service/QueueService.php | getQueueDepth | Queue Detail View | 0.78 | (NEEDS-REVIEW) |
| lib/Service/QueueService.php | isAtCapacity/processOverflow | Priority-Based Queue Ordering | 0.75 | (NEEDS-REVIEW; overflow handling) |
| lib/BackgroundJob/QueueOverflowJob.php | run | Priority-Based Queue Ordering | 0.78 | (NEEDS-REVIEW) |

### capability: public-intake-forms

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/PublicFormController.php | show | Public Form API Routes | 0.92 | API route |
| lib/Controller/PublicFormController.php | submit | Form Submission Creates CRM Entities | 0.95 | REQ name 1:1 |
| lib/Controller/IntakeFormController.php | embed | Form Embedding | 0.95 | REQ name 1:1 |
| lib/Controller/IntakeFormController.php | export | Submission History and Export | 0.92 | export endpoint |
| lib/Service/IntakeFormService.php | validateSubmission | Form Submission Creates CRM Entities | 0.85 | validation |
| lib/Service/IntakeFormService.php | isSpam/isRateLimited | Spam Protection | 0.85–0.95 | REQ name 1:1 |
| lib/Service/IntakeFormService.php | mapToEntity | Field-to-Entity Mapping | 0.95 | REQ name 1:1 |
| lib/Service/IntakeFormService.php | generateIframeEmbed/generateJsEmbed | Form Embedding | 0.92 | embed generators |
| lib/Service/IntakeFormService.php | exportCsv | Submission History and Export | 0.92 | CSV export |

### capability: crm-workflow-automation

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/AutomationController.php | metadata | Automation Management | 0.78 | (NEEDS-REVIEW) |
| lib/Controller/AutomationController.php | test | Automation Builder UI | 0.75 | (NEEDS-REVIEW) |
| lib/Service/AutomationService.php | <all> | CRM Automation Triggers | 0.85 | broad service |
| lib/Service/ObjectEventDispatcher.php | <all> | CRM Automation Triggers | 0.85 | object-event dispatch |
| lib/Service/ObjectEventHandlerService.php | handleCreated/handleUpdated/fireAutomations/fireUpdateAutomations | CRM Automation Triggers | 0.85–0.88 | trigger dispatchers |
| lib/Service/ObjectUpdateDiffService.php | dispatchAssigneeChangeIfNeeded/dispatchStageChangeIfNeeded/dispatchStatusChangeIfNeeded | Trigger Conditions | 0.82 | field-change conditions |

### capability: email-calendar-sync

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/CalendarSyncService.php | buildCalendarLinkData/generateVEvent/isEventPassed | Calendar events MUST sync with Nextcloud Calendar | 0.82–0.92 | calendar-sync |
| lib/Service/EmailSyncService.php | <all> | Emails MUST be automatically linked to CRM contacts | 0.88 | email-sync core |
| lib/BackgroundJob/EmailSyncJob.php | run | Sync MUST be near-real-time and handle conflicts | 0.85 | sync driver |

### capability: pipeline

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/PipelineStageData.php | <all> | REQ-PIPE-003 (Default Pipelines) | 0.88 | seed data |

### capability: request-management (MCP)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Mcp/PipelinqToolProvider.php | <all> | REQ-RM-010 (Request CRUD) | 0.78 | (NEEDS-REVIEW; MCP-specific REQ missing) |

### capability: skill-routing (Default Skills)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/SettingsService.php | createDefaultSkills | Default Skills | 0.92 | REQ name 1:1 |

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: activity-timeline (8 methods)

Private helpers inside ActivityTimelineService that Pass B could not inherit cleanly. They serve the Timeline-view REQs but their specific behaviors (date-range filtering, ISO-8601 duration parsing for worklog, source-type normalization) are not called out in any scenario.

- lib/Service/ActivityTimelineService.php::sourceToActivityType — source-type → activity-type mapping
- lib/Service/ActivityTimelineService.php::normaliseTypes — type-filter normaliser
- lib/Service/ActivityTimelineService.php::querySchema — OR query helper
- lib/Service/ActivityTimelineService.php::normaliseResultset — result normaliser
- lib/Service/ActivityTimelineService.php::withinDateRange — date-range filter (covered conceptually by "Timeline MUST be filterable")
- lib/Service/ActivityTimelineService.php::extractObjectArray — result extractor
- lib/Service/ActivityTimelineService.php::isoDurationToSeconds — worklog duration parser
- lib/Service/ActivityTimelineService.php::secondsToIsoDuration — worklog duration formatter

### cluster: admin-settings (2 methods)

- lib/Service/SettingsService.php::getConfigValue — low-level config getter
- lib/Service/SettingsService.php::setConfigValue — low-level config setter

Both are generic IAppConfig wrappers; consider classifying as plumbing or surfacing as REQ in a "Settings persistence storage primitives" addendum.

## Bucket 2b — No capability owner (reverse-spec --cluster)

**Empty.** Every PHP file in lib/ mapped to an existing capability. The pipelinq spec taxonomy is well-aligned with code layout.

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (3 entries)

- **queue-management#Queue Entity CRUD** — removed-lines cache shows Queue-related deletions; current code provides DefaultQueueService + assign/remove helpers but no full CRUD controller. Likely pruned in favour of seed-only management; verify intent.
- **contact-relationship-mapping#Relationship entity / types** — 35 hits for "Relationship" in removed lines. Earlier prototype existed; current capability is `status: partial`. Triage whether to revive or formally archive.
- **product-catalog#Product-Lead Linking via LeadProduct** — `lead-product-link` capability exists separately; some implementation churn visible. Verify the linkage is still intact.

### 3b — never implemented (80+ entries; abbreviated)

Themes:
- **Draft-only specs:** klantbeeld-360 (9 REQs), pipeline-insights (10), product-catalog-quoting (10), contactmomenten-rapportage (5 of 10), kcc-werkplek (23, UI-heavy), kennisbank (3), terugbel-taakbeheer (duplicates callback-management)
- **Frontend-only REQs:** Most entity-notes V1 features (rich-text, @mentions, attachments, pinning, templates, visibility, inline editing) ship in Vue if at all — not in lib/
- **Schema/i18n-only:** register-i18n (12), product-service-catalog (16) deliver via OR schemas + JSON, not PHP
- **Enterprise tier deferred:** webhook notifications, notification analytics, batch rate limiting, BRP integration, automated email sequences, n8n integration, multi-step automation chains, alerting thresholds
- **Callback front-end gap:** Task List/Detail views, Task Schema Registration UI, Notification Integration UI — backend is annotated but front-end REQs are missing PHP/Vue evidence

Full list in `coverage-report.json` → `bucket_3b`.

## Bucket 4 — ADR conformance findings

**Good news:** zero forbidden patterns (no `var_dump`/`die`/`dd`/`print_r`/`error_log`), zero direct SQL, **all 99 lib/ files have SPDX-License-Identifier + @license + @copyright headers.**

**Missing-spec-in-file-docblock:** approximately 70 of 99 PHP files lack any `@spec openspec/changes/...` tag in their file docblock. Coverage of annotation is concentrated in 4 capabilities (callback-management, skill-routing, task-background-jobs, activity-timeline). Capabilities with rich Bucket 1 matches but zero annotations:

- `lib/Service/`: NotificationService, KennisbankService, IcpConfigService/Reader, MetricsRepository/Formatter, EmailSyncService, CalendarSyncService, ProspectDiscoveryService, ProspectScoringService, AutomationService, QueueService, DefaultQueueService, DefaultPipelineService, ContactSyncService, ContactVcardService, ContactImportService, NotesService, NoteEventService, ContactmomentService, ReportingService, ComplaintSlaService, SettingsService, SettingsLoadService, SettingsMapBuilder, ConfigFileLoaderService, SchemaMapService, ObjectEventDispatcher, ObjectEventHandlerService, ObjectUpdateDiffService, IntakeFormService, TaskService, KvkApiClient, KvkResultMapper, OpenCorporatesApiClient, OpenCorporatesResultMapper, SystemTagService, SystemTagCrudService, PipelineStageData, ContactLinkedUidsService, ContactDataBuilder, ContactVcardPropertyBuilder, ContactVcardWriterService, MetricsRepository, NotesService
- `lib/Controller/`: SettingsController, RequestChannelController, LeadSourceController, DashboardController, HealthController, ContactSyncController, NotesController, ProspectController, ProspectSettingsController, ContactmomentController, ReportingController, KennisbankController, PublicKennisbankController, IntakeFormController, PublicFormController, AutomationController, MetricsController, PublicSurveyController
- `lib/BackgroundJob/`: KennisbankReviewJob, ComplaintSlaJob, EmailSyncJob, TaskExpiryJob, TaskEscalationJob, QueueOverflowJob

These are the highest-value targets for `/opsx-annotate pipelinq`.

## Notes for the human reviewer

1. **Spec heading style is mixed.** ~14 specs use the legacy `### REQ-XXX-NNN:` convention; ~22 use the canonical OpenSpec `### Requirement: <title>`. Scanner used a flexible regex `^### (Requirement:|REQ-|[A-Z]{2,4}-[0-9])` to inventory both styles. Consider a one-time normalisation sweep so future scans (and `/opsx-annotate`) don't need this fallback.
2. **`terugbel-taakbeheer` overlaps with `callback-management`.** All 9 REQs are draft and duplicate scope. Recommend archive or merge into callback-management.
3. **`klantbeeld-360`, `pipeline-insights`, `product-catalog-quoting`, `kcc-werkplek`, `crm-workflow-automation` are draft specs with no/sparse code.** These bloat Bucket 3b. Either schedule implementation or mark deferred.
4. **Vue-side coverage scan is missing.** 80 .vue files in src/ likely cover many "UI-heavy" REQs flagged as 3b here (entity-notes editors, kcc-werkplek workspace, kennisbank navigation, klantbeeld-360 panels). A Vue scan would shrink Bucket 3b meaningfully.
5. **No `status: redirect` specs and no `.opsx-ignore` file.** Nothing was suppressed.
6. **Bucket 1 has 14 NEEDS-REVIEW flags** (confidence 0.70–0.85). The recurring pattern is: settings-related helpers where multiple REQs in `admin-settings` could plausibly apply; orchestrator services that span REQs (ProspectDiscoveryService, QueueService overflow path); MCP tool which has no MCP-specific REQ.
7. **Annotation backlog is large (~70 files) but mechanically simple.** Most matches are 0.85+ and capability-aligned by directory name. `/opsx-annotate pipelinq` is well-justified as the next step.
8. **Pipeline spec (REQ-PIPE-001..012) is mostly delivered via OpenRegister schemas + Vue (kanban board)** — only PipelineStageData.php surfaces as PHP. Most pipeline REQs are 3b in a PHP-only scan but are not necessarily "never implemented."
9. **Branch is `feat/features-tab-wiring` (not `development`).** Scan ran against the working tree; the gap between branches is likely small but worth noting.
