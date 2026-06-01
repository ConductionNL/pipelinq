# Tasks: AVG-verzoeken Workflow

> **Build note (Hydra build #45 — declarative implementation per ADR-001/022/031).**
> This change was authored before ADR-031. The original tasks.md describes a
> bespoke implementation (custom Entity/Mapper, `CreateXTable` migrations,
> hand-written state-machine/notification/aggregation services). That shape is
> now an anti-pattern. The build delivers the workflow **declaratively**:
> - **6 schemas** (`avgVerzoek`, `termijnEvent`, `bewijsItem`, `exportBundle`,
>   `weigering`, `redactieActie`) live in `lib/Settings/pipelinq_register.json` —
>   no own DB tables (ADR-001), so all `CreateXTable` migration tasks are N/A.
> - **Lifecycle** (intake → in-behandeling → bewijs-verzamelen → redactie →
>   bundle-genereren → afgerond / weigering-opgesteld → geweigerd; denial
>   concept → definitief → overschreven) is `x-openregister-lifecycle` with
>   server-authoritative per-transition `authorization` (ADR-005), replacing the
>   bespoke state-machine controllers/services.
> - **Deadline derivation** (`dagenResterend`, `termijnOverschreden`) is
>   `x-openregister-calculations` — fresh on read, no DeadlineTrackerService.
> - **Escalation/breach/resolution alerts** are `x-openregister-notifications`,
>   replacing AvgNotificationService.
> - **5-year retention + 30-day evidence pseudonymisation** is
>   `x-openregister-archival`, replacing RetentionService + RetentionCleanupJob.
> - **Views** (AVG Requests index + detail) are declared in `src/manifest.json`
>   (ADR-024), replacing the bespoke Vue views/components.
> - **i18n** keys are in `l10n/{en,nl}.{json,js}` (ADR-007/025).
>
> See `design.md` → "Declarative-vs-imperative decision" for the genuinely
> imperative parts (PAdES-LTV signing, BRP lookup, OpenConnector federated
> query, DocuDesk PDF render, AP ZIP export) that require external/leaf apps not
> present in this environment and are honestly **deferred** (tracked under
> pipelinq#45) rather than stubbed.

## 0. Pre-implementation Review

- [ ] 0.1 Verify ADR-000 data model is current and includes all required entity definitions
- [ ] 0.2 Confirm OpenRegister platform supports pseudonymization capability for 30-day evidence masking
- [ ] 0.3 Review BSN Validation capability requirements and interface (sister feature)
- [ ] 0.4 Obtain PKIoverheid certificate path and PAdES-LTV signing library availability
- [ ] 0.5 Confirm DocuDesk API availability and PDF template versioning capability
- [ ] 0.6 Validate OpenConnector source mappings and existing AVG-export-endpoint implementations
- [ ] 0.7 Review Nextcloud Mail API for secure link delivery and template management
- [ ] 0.8 Confirm BRP lookup capability and binding mechanism for "handling AVG request" use case
- [ ] 0.9 Check Procest integration: whether improvement items can be auto-created and linked

## 1. Data Model & Database Schema

- [x] 1.1 Add `AvgVerzoek` schema (declared in `lib/Settings/pipelinq_register.json` with full lifecycle/calculations/notifications/archival):
  - Properties: `kenmerk`, `ingediendOp`, `ingediendVia`, `verzoekerContact`, `verzoekerNaam`, `verzoekerBsn`, `verzoekerBsnGeverifieerd`, `artikel`, `specifiekeVraag`, `scope`, `wettelijkeTermijnVerloopt`, `verlengdMet`, `verlengingsgrond`, `status`, `behandelaar`, `fgGeinformeerd`, `dpiaFlag`, `uitkomst`, `afgerondOp`, `bewijsbundel`, `retentieTot`
  - Example in Dutch with valid field values per context-brief

- [x] 1.2 Add `TermijnEvent` schema (declared in register; immutable timeline log):
  - Properties: `verzoekId`, `type` (enum: ontvangstbevestiging-verstuurd, termijn-overschreden, escalatie-3dagen, collectie-fout), `tijdstip`, `deadline`, `automatisch`, `geslaagd`, `details`

- [x] 1.3 Add `BewijsItem` schema (declared in register; archival pseudonymisation policy):
  - Properties: `verzoekId`, `bronApp`, `bronRegister`, `bronObject`, `categorie`, `verzameldOp`, `rechtsgrond`, `opgenomenInExport`, `geredigeerd`, `redactiereden`, `inhoudPreview`, `gedupliceerd` (optional)

- [x] 1.4 Add `ExportBundle` schema (declared in register):
  - Properties: `verzoekId`, `samengesteldOp`, `samengesteldDoor`, `bevatItems`, `formaat` (array), `bestandsgrootte`, `sha256`, `ondertekend`, `ondertekeningsType`, `uitgeleverdVia`, `uitgeleverdOp`, `downloadVerloopt`, `downloadCode`, `verzoekerOntvangstBevestigd`

- [x] 1.5 Add `Weigering` schema (declared in register; concept→definitief→overschreven lifecycle):
  - Properties: `verzoekId`, `weigering` (enum: geheel, gedeeltelijk), `geweigerdeOnderdelen`, `grond` (enum: art-23-lid-1-sub-a ... art-23-lid-3), `toelichtingAvg23`, `verwijzingBezwaarProcedure`, `verwijzingAp`, `ondertekendDoor`, `ondertekendOp`

- [x] 1.6 Add `RedactieActie` schema (declared in register; immutable before/after audit):
  - Properties: `bundleId`, `bewijsItemId`, `veldpad`, `voorWaarde`, `naWaarde`, `uitgevoerdDoor`, `uitgevoerdOp`, `grond` (enum: bescherming-rechten-derden, wettelijke-verplichting, bedrijfsgeheim)

- [x] 1.7 ~~Create database migrations~~ — **N/A (ADR-001)**: Pipelinq is a thin client on OpenRegister with no own DB tables. Objects persist via the OR ObjectService; the OR engine derives storage from the schema declarations. No `CreateXTable` migrations.
  - `CreateAvgVerzoekenTable`: with indexes on `artikel`, `wettelijke_termijn_verloopt`, `status`, `behandelaar`, `dpia_flag`
  - `CreateTermijnEventsTable`: with index on `verzoek_id`, `type`
  - `CreateBewijsItemsTable`: with indexes on `verzoek_id`, `bron_app`
  - `CreateExportBundlesTable`: with indexes on `verzoek_id`, `ondertekend`
  - `CreateWeigeringenTable`: with index on `verzoek_id`
  - `CreateRedactieActiesTable`: with indexes on `bundle_id`, `bewijs_item_id`

- [x] 1.8 ~~Add migration to enrich `contacts` table~~ — **N/A (ADR-001/022)**: the contact↔AVG link is expressed by `avgVerzoek.verzoekerContact` (UUID reference); a handler queries open AVG requests for a contact via the OR ObjectService filter `verzoekerContact == {uuid}`. No schema mutation of the OR `contact` and no own link table (ADR-022 anti-pattern).

## 2. Backend: Controllers & Request Handling

> **Declarative note:** generic CRUD, list/filter, status transitions, and the
> intake lifecycle are provided by the OpenRegister object API + the schema's
> `x-openregister-lifecycle` (server-authoritative transition authorization, no
> IDOR). No bespoke `AvgRequestController` / `DenialController` / `ExtensionController`
> / `RetentionController` state-machine code is authored (ADR-022/031/005). The
> action endpoints that wrap **external** systems (`collect-evidence`,
> `generate-bundle`, `ap-escalate`) are deferred — see design.md.

- [ ] 2.1 Create `lib/Controller/AvgRequestController.php`:
  - `POST /api/v2/avg-verzoeken` — intake form submission with validation:
    - Mandatory: `artikel`, `specifiekeVraag`, `scope`
    - Optional: `verzoekerContact` (for manual registration)
    - On submission: call BSN validation, create AvgVerzoek, calculate deadline, create TermijnEvent
  - `GET /api/v2/avg-verzoeken` — list with filtering by `status`, `artikel`, `behandelaar`, `dpiaFlag`
  - `GET /api/v2/avg-verzoeken/{id}` — fetch full record with all relations
  - `PATCH /api/v2/avg-verzoeken/{id}` — update handler, status, notes
  - `DELETE /api/v2/avg-verzoeken/{id}` — only allowed if not in retention window; else return 403

- [ ] 2.2 Create `lib/Controller/EvidenceCollectionController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/collect-evidence` — start async job, return job ID
  - `GET /api/v2/avg-verzoeken/{id}/evidence-status` — return progress: `{ collected: N, pending: M, failed: K }`
  - `GET /api/v2/avg-verzoeken/{id}/bewijs-items` — list all evidence with deduplication flagging

- [ ] 2.3 Create `lib/Controller/BundleGenerationController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/generate-bundle` — assemble bundle, call DocuDesk, sign with PKIoverheid cert
  - `GET /api/v2/export-bundles/{bundleId}` — fetch bundle metadata (not content; content via separate secure download)
  - `GET /api/v2/export-bundles/{bundleId}/download?token={token}` — validate token, return PDF/JSON (one-time use)
  - On first download, set `verzoekerOntvangstBevestigd: true` and log timestamp

- [ ] 2.4 Create `lib/Controller/RedactionController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/redact` — create RedactieActie:
    - Input: `{ "bewijsItemId": "...", "veldpad": "...", "naWaarde": "..." }`
    - Validation: block if field is citizen's own data without Art. 23 grounds
    - Return: updated BewijsItem with `geredigeerd: true`
  - `GET /api/v2/avg-verzoeken/{id}/redaction-summary` — all redactions for bundle with before/after
  - `POST /api/v2/avg-verzoeken/{id}/approve-redactions` — mark all redactions approved for bundle generation

- [ ] 2.5 Create `lib/Controller/DenialController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/deny` — create Weigering:
    - Input: `{ "weigering": "geheel|gedeeltelijk", "grond": "art-23-...", "toelichtingAvg23": "...", "geweigerdeOnderdelen": [...] }`
    - Validation: ensure toelichtingAvg23 has min. 100 chars
    - Validation: ensure verwijzingAp URL is present before allowing finalization
    - Return: Weigering record
  - `GET /api/v2/avg-verzoeken/{id}/weigering` — fetch denial if exists
  - `PATCH /api/v2/weigeringen/{id}` — update denial (only if not yet finalized)

- [ ] 2.6 Create `lib/Controller/ExtensionController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/extend` — request 60-day extension:
    - Input: `{ "verlengingsgrond": "..." }`
    - Validation: only if current date <= day 30, only if not already extended
    - Validation: grond must match allowed values (complexiteit, aantal verzoeken, etc.)
    - On success: update `verlengdMet: 60`, set new deadline, generate email to citizen
  - Email MUST be held for handler approval (4-eyes) before sending

- [ ] 2.7 Create `lib/Controller/RetentionController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/archive` — transition to archived (called after resolution)
  - `DELETE /api/v2/avg-verzoeken/{id}` — check retention window; refuse if active

- [ ] 2.8 Create `lib/Controller/ApEscalationController.php`:
  - `POST /api/v2/avg-verzoeken/{id}/ap-escalate` — export complete dossier as ZIP:
    - Package structure per REQ-AVG-008
    - Sign ZIP with organization key
    - Return download link (no time limit for AP)
  - `POST /api/v2/avg-verzoeken/{id}/mark-ap-complaint` — update `dpiaFlag: "ap-klacht"`

## 3. Backend: Services & Business Logic

> **Declarative note (ADR-031):** `DeadlineTrackerService` → `x-openregister-calculations`
> (`dagenResterend`, `termijnOverschreden`) + `x-openregister-notifications`
> (deadlineEscalation / deadlineBreached / requestResolved). `AvgNotificationService` →
> `x-openregister-notifications`. `RetentionService` (pseudonymise + delete) →
> `x-openregister-archival` (P5Y + 30-day evidence pseudonymisation rule). These
> three services are NOT authored. The remaining services below are genuinely
> imperative external-system adapters (ADR-003) and are **deferred** until their
> leaf/external dependencies are present — see design.md "Declarative-vs-imperative decision".

- [ ] 3.1 Create `lib/Service/EvidenceCollectionService.php`:
  - `collectFromOpenRegister(AvgVerzoek $request)` — query OpenRegister for objects matching BSN + scope
  - `collectFromBrp(AvgVerzoek $request)` — call BSN validation capability
  - `collectFromOpenConnector(AvgVerzoek $request)` — query all registered AVG-export-endpoints
  - `deduplicateItems(array $bewijsItems)` — detect content-hash matches, mark gedupliceerd
  - `handleSourceTimeout(AvgVerzoek $request, string $sourceName)` — create error BewijsItem
  - Return: array of created BewijsItem records

- [ ] 3.2 Create `lib/Service/BundleService.php`:
  - `assemble(AvgVerzoek $request)` — prepare JSON structure grouping BewijsItems by categorie
  - `renderToPdf(array $bundleData, AvgVerzoek $request)` — call DocuDesk render API with org template
  - `sign(string $pdfPath)` — PAdES-LTV signing with PKIoverheid cert:
    - Use third-party library (e.g., openssl, xades, or PHP library)
    - Embed timestamp from Dutch TSA
    - Return signed PDF binary
  - `computeHash(string $pdfPath)` — SHA-256 hash of finalized PDF
  - `generateDownloadLink(ExportBundle $bundle)` — create 30-day limited token

- [ ] 3.3 Create `lib/Service/RedactionService.php`:
  - `isOwnData(string $fieldPath, AvgVerzoek $request)` — heuristic: check if field likely contains citizen's name/address
  - `applyRedaction(RedactieActie $action, array $bewijsContent)` — JSONPath replacement
  - `validateBeforeFinalization(AvgVerzoek $request)` — ensure no citizen-owned fields are redacted without Art. 23 grounds

- [ ] 3.4 Create `lib/Service/DpiaDetectionService.php`:
  - `analyzePatterns()` — run weekly analysis:
    - Group AvgVerzoeken by `artikel` + `scope`
    - Count in 30-day rolling window
    - If count >= DPIA_THRESHOLD (default 10), flag all matching requests
  - `getTopPatterns()` — return list of patterns for FG dashboard
  - `linkToProcest(AvgVerzoek $request)` — auto-create Procest improvement item if flagged

- [ ] 3.5 Create `lib/Service/DeadlineTrackerService.php`:
  - `checkEscalations()` — hourly job:
    - Find requests with `status: "in-behandeling"` and deadline < 72 hours
    - Notify team lead, flag request in red
  - `checkBreaches()` — daily job:
    - Find requests past deadline and still in-treatment
    - Create TermijnEvent with `type: "termijn-overschreden"`
    - Notify FG
    - Log to SIEM
  - `send7DayReminder()` — daily check, send reminder if deadline == today + 7 days

- [ ] 3.6 Create `lib/Service/RetentionService.php`:
  - `pseudonymizeEvidence(BewijsItem $item)` — mask personal data in inhoudPreview, keep metadata
  - `deleteExpiredDossier(AvgVerzoek $request)` — hard-delete after 5-year window
  - `updateRetentionDate(AvgVerzoek $request)` — set `retentieTot` on resolution

- [ ] 3.7 Create `lib/Service/AvgNotificationService.php`:
  - `sendReceiptConfirmation(AvgVerzoek $request)` — email to citizen with reference + deadline
  - `sendExtensionNotification(AvgVerzoek $request, string $reason)` — email with new deadline + justification
  - `sendDenialLetter(Weigering $weigering, AvgVerzoek $request)` — PDF letter with AP contact info
  - `sendDeadlineReminder(AvgVerzoek $request, int $daysRemaining)` — handler reminder
  - `sendEscalationAlert(AvgVerzoek $request)` — team lead notification
  - All methods: return email object for handler approval, not auto-send (4-eyes control)

## 4. Backend: Background Jobs

> **Declarative note (ADR-031):** `DeadlineTrackerJob`, `PseudonymizationJob`,
> `RetentionCleanupJob` are replaced by `x-openregister-calculations` (fresh-on-read,
> no job) + scheduled `x-openregister-notifications` + `x-openregister-archival`
> (the OR retention/archival engine performs the 30-day pseudonymisation and 5-year
> disposal). They are NOT authored. `CollectEvidenceJob` and `DpiaPatternDetectionJob`
> orchestrate external sources / cross-app Procest creation and are **deferred** to
> OR's `ScheduledWorkflow` + n8n adapter (ADR-031 §"What apps SHOULD still write")
> once the OpenConnector/Procest dependencies exist — see design.md.

- [ ] 4.1 Create `lib/Job/CollectEvidenceJob.php`:
  - Input: `verzoekId`, `sourcesToQuery` (array)
  - Call `EvidenceCollectionService` for each source
  - Timeout handling: 10 seconds per source
  - On completion: update request status, log summary

- [ ] 4.2 Create `lib/Job/DeadlineTrackerJob.php`:
  - Run hourly and daily
  - Hourly: check escalations (<72 hours)
  - Daily: check 7-day reminder, check breaches

- [ ] 4.3 Create `lib/Job/DpiaPatternDetectionJob.php`:
  - Run weekly (e.g., Monday morning)
  - Call `DpiaDetectionService->analyzePatterns()`
  - Flag requests, notify FG, optionally auto-create Procest items

- [ ] 4.4 Create `lib/Job/PseudonymizationJob.php`:
  - Run daily
  - Find BewijsItems with `verzameldOp` > 30 days ago
  - Call `RetentionService->pseudonymizeEvidence()` per item
  - Log anonymized items

- [ ] 4.5 Create `lib/Job/RetentionCleanupJob.php`:
  - Run daily
  - Find AvgVerzoeken with `retentieTot` < today
  - Call `RetentionService->deleteExpiredDossier()`
  - Log deletion to SIEM

## 5. Frontend: Views & Components

- [x] 5.1 AVG Requests index view — **declared in `src/manifest.json`** (`AvgVerzoeken` index page: register `pipelinq`, schema `avgVerzoek`, columns kenmerk/artikel/status/behandelaar/wettelijkeTermijnVerloopt/dpiaFlag) + menu entry (order 85). Deadline urgency colouring is driven by the `dagenResterend`/`termijnOverschreden` calculated fields. Per ADR-024 no bespoke `AvgDashboard.vue` is authored:
  - Layout: Kanban board OR table view with status columns (Intake → In Behandeling → Afgerond)
  - Color-coding by deadline urgency:
    - Green: >7 days remaining
    - Yellow: 3–7 days remaining
    - Red: <3 days or breached
  - Filters: `status`, `artikel`, `behandelaar` (for team lead / FG: all)
  - Quick-access buttons: "Collect Evidence", "Generate Bundle", "Redact Data"
  - Display cards: request kenmerk, citizen name (masked), deadline, handler name
  - FG-only elements: DPIA flag badge, breach log link

- [x] 5.2 AVG Request detail view — **declared in `src/manifest.json`** (`AvgVerzoekDetail` detail page with sidebar; OR-driven detail renderer shows the object's fields, audit trail, notes, tasks, files, and lifecycle transitions). Per ADR-024 no bespoke `AvgRequestDetail.vue` is authored:
  - Tabbed layout:
    - **Intake**: summary of request, citizen info, article type, scope, submitted via (web/manual)
    - **Evidence**: list of BewijsItems with source badges (OpenRegister, BRP, OpenConnector), deduplication indicator
    - **Redaction**: editor for applying redactions with before/after preview
    - **Bundle**: PDF preview + JSON download when ready
    - **Denial**: Weigering form if applicable
    - **Timeline**: TermijnEvent log (deadlines, escalations, breaches)
  - All tabs read-only if `status: "afgerond"` (archived)

- [ ] 5.3 Create `src/components/AvgIntakeForm.vue`:
  - Inputs:
    - Article radio buttons (art. 15, 16, 17, 18, 20) with icons + Dutch labels
    - Free-text "Uw verzoek" (your request) — optional if article is pre-selected
    - Scope multi-select (autocomplete from org config)
    - Citizen name, BSN, email, phone
    - Checkbox: "BSN verified via BRP" (auto-checked if BSN validation succeeded)
  - On submit: create AvgVerzoek, show success message with kenmerk
  - Error states: invalid BSN, missing required fields

- [ ] 5.4 Create `src/components/DeadlineCounter.vue`:
  - Display: "X days remaining" with countdown color:
    - Green: >7 days
    - Yellow: 3–7 days
    - Red: <3 days
  - Subtext: "Due: {date} {time}" with timezone
  - If extended: show "Extended +60 days" in smaller text
  - If breached: show "OVERSCHREDEN" in large red text with warning icon

- [ ] 5.5 Create `src/components/EvidenceCollector.vue`:
  - Button: "Verzamel bewijs van bronnen" (collect evidence from sources)
  - On click: start job, show progress bar:
    - "Querying OpenRegister..."
    - "Querying BRP..."
    - "Querying OpenConnector sources..."
    - "Processing 34 items..."
  - Final display: "34 items collected. 2 sources did not respond (manual supplementation may be needed)."
  - Warning banner if any source timed out or failed
  - List: each source with item count + status (success/timeout)

- [ ] 5.6 Create `src/components/RedactionEditor.vue`:
  - Display: list of BewijsItems in a filtered view (show redactable items)
  - For each item: expandable JSON preview
  - Click on field: inline editor with "Redigeer" button
  - Modal: confirm redaction with:
    - Before value
    - After value (default: `[redacted: {reason}]`)
    - Reason dropdown (bescherming-rechten-derden, wettelijke-verplichting, bedrijfsgeheim)
  - Warning: if field looks like citizen's own data, show: "⚠ This appears to be the citizen's own data. Redacting requires Art. 23 grounds."
  - Approval flow: "Approve all redactions" button that marks bundle ready for generation

- [ ] 5.7 Create `src/components/BundlePreview.vue`:
  - If bundle not yet generated: show button "Genereer bundle" (generate bundle)
  - On generation: spinner, then:
    - PDF viewer (embedded or link to download)
    - JSON preview (collapsible, syntax highlighted)
    - Metadata: generation timestamp, handler name, item count, file size, signature type
    - Button: "Download PDF" (secure link, 30-day validity)
    - Button: "Download JSON" (direct)
    - Button: "Verzend naar verzoeker via e-mail" (send to citizen via email with secure link)

- [ ] 5.8 Create `src/components/DenialForm.vue`:
  - Inputs:
    - Radio: "Geheel geweigerd" vs. "Gedeeltelijk geweigerd"
    - If partial: multi-select of scopes to deny (checkboxes)
    - Dropdown: GDPR Art. 23 exception grounds (Art. 23(1)(a)–(f) and Art. 23(3))
    - Text area: motivation (min. 100 chars) with live counter
    - Validation: ensure motivation explains the chosen ground
  - On submit:
    - Check: verwijzingAp URL is present (block if missing)
    - Create Weigering record
    - Show message: "Denial recorded. Handler must sign before sending to citizen."
    - Button: "Show denial letter preview"

- [ ] 5.9 Create `src/components/DpiaFlagBadge.vue`:
  - Small badge displayed on request cards / details when `dpiaFlag: true`
  - Icon: 🔍 or ⚠ in purple
  - Hover tooltip: "This request is flagged for Data Protection Impact Assessment review"
  - Link to: related requests with same pattern, Procest improvement item (if created)

## 6. Frontend: Utilities & Helpers

- [ ] 6.1 Create `src/utils/deadlineUtils.ts`:
  - `calculateDeadline(submittedAt: Date): Date` — add 30 days
  - `daysRemaining(deadline: Date): number`
  - `getUrgencyColor(daysRemaining: number): string` — green / yellow / red
  - `deadlineString(deadline: Date): string` — human-readable format

- [ ] 6.2 Create `src/utils/articleLabels.ts`:
  - Map `artikel` enum to Dutch labels:
    - `art-15-inzage` → "Inzagerecht (Art. 15)"
    - `art-16-rectificatie` → "Rectificatie (Art. 16)"
    - etc.

- [ ] 6.3 Create `src/utils/scopeLabels.ts`:
  - Map scope identifiers to Dutch labels (configurable per org)

- [ ] 6.4 Create `src/utils/bsnValidator.ts`:
  - `isValidBsn(bsn: string): boolean` — validate 9-digit format

## 7. i18n: Translation Keys

- [x] 7.1 Added AVG keys to `l10n/en.json` (+ `l10n/en.js`):
  - "AVG Request - Article {article}"
  - "Legal deadline"
  - "Days remaining"
  - "Extension with justification"
  - "Denied under GDPR Art. 23"
  - "Redact for third-party protection"
  - "Evidence collection in progress"
  - "PDF and JSON export ready"
  - "Receive evidence collection status"
  - And all 13 requirements' field-level messages

- [x] 7.2 Added same keys to `l10n/nl.json` (+ `l10n/nl.js`) with Dutch translations

## 8. Configuration & Admin Settings

- [ ] 8.1 Create admin settings form `src/views/admin/AvgRequestSettings.vue`:
  - DPIA threshold (default: 10 requests in 30 days)
  - Evidence retention days (default: 30)
  - Dossier retention years (default: 5)
  - PKIoverheid certificate path
  - OpenConnector source mappings
  - Email templates: receipt confirmation, extension notification, denial letter, deadline reminders
  - Toggle: auto-create Procest items on DPIA flag (yes/no)

- [ ] 8.2 Create settings migration: initialize default values in `config` table

## 9. API Documentation

- [ ] 9.1 Document all new OpenAPI endpoints in `docs/api/avg-requests.md`:
  - Request/response schemas for each endpoint
  - Example curl commands
  - Error codes and messages

- [ ] 9.2 Add OpenConnector integration docs: `docs/integration/openconnector-avg-export.md`

## 10. Integration Tests

- [ ] 10.1 Create `tests/Integration/AvgIntakeTest.php`:
  - Test intake form submission with valid BSN
  - Test invalid BSN rejection
  - Test automatic deadline calculation
  - Test multi-article choice

- [ ] 10.2 Create `tests/Integration/EvidenceCollectionTest.php`:
  - Mock OpenRegister, BRP, OpenConnector sources
  - Test collection from each source
  - Test timeout handling
  - Test deduplication

- [ ] 10.3 Create `tests/Integration/BundleGenerationTest.php`:
  - Test PDF rendering with DocuDesk mock
  - Test PAdES-LTV signing
  - Test SHA-256 hashing
  - Test secure download link generation and one-time use

- [ ] 10.4 Create `tests/Integration/RedactionTest.php`:
  - Test field-level redaction
  - Test citizen-owned-data protection
  - Test before/after comparison

- [ ] 10.5 Create `tests/Integration/DeadlineTrackerTest.php`:
  - Mock date advancement
  - Test 7-day reminder
  - Test 3-day escalation
  - Test deadline breach logging

- [ ] 10.6 Create `tests/Integration/DpiaPatternDetectionTest.php`:
  - Create 10+ similar requests
  - Run pattern detection job
  - Verify flagging and FG notification

## 11. Manual Testing

- [ ] 11.1 End-to-end Art. 15 (access) request:
  - Submit web form
  - Verify deadline calculated
  - Trigger evidence collection
  - Verify items collected from OpenRegister
  - Redact third-party names
  - Generate bundle (PDF + JSON)
  - Verify PDF is signed and verifiable
  - Download via secure link
  - Check 30-day expiry

- [ ] 11.2 End-to-end Art. 17 (erasure) request with partial denial:
  - Submit request for erasure
  - Collect evidence from multiple sources
  - Mark some scopes as "cannot erase" (legal hold)
  - Create Weigering with Art. 23(1)(e) grounds
  - Generate bundle with denial letter
  - Verify AP complaint reference is present
  - Send to citizen

- [ ] 11.3 Extension workflow:
  - Create request
  - Advance to day 25
  - Extend with justification
  - Verify citizen email is held for approval
  - Approve and send
  - Verify new deadline is set

- [ ] 11.4 DPIA pattern detection:
  - Create 10+ erasure requests with same scope
  - Run weekly detection job
  - Verify flag is set on all requests
  - Verify FG is notified
  - Create Procest improvement item

- [ ] 11.5 Deadline breach & escalation:
  - Create request
  - Advance to day 28
  - Verify 7-day reminder sent
  - Advance to day 32 (overdue)
  - Verify breach logged and FG notified

- [ ] 11.6 Retention & cleanup:
  - Archive request after resolution
  - Verify dossier appears in Archive tab only
  - Advance 30 days
  - Verify evidence is pseudonymized
  - Advance 5 years
  - Run cleanup job
  - Verify request is deleted

## 12. Documentation & Training

- [ ] 12.1 Write handler documentation: `docs/user/avg-requests.md`
  - How to intake a request
  - How to collect evidence
  - How to redact third-party data
  - How to generate and send bundle
  - How to handle denial / extension
  - How to escalate to FG

- [ ] 12.2 Write FG/compliance documentation: `docs/compliance/avg-requests.md`
  - Legal deadlines & compliance requirements
  - DPIA pattern detection
  - AP escalation process
  - Retention & cleanup policies

- [ ] 12.3 Write admin documentation: `docs/admin/avg-requests.md`
  - Configuration: DPIA threshold, retention settings, email templates
  - OpenConnector source mapping
  - Monitoring & reporting

- [ ] 12.4 Create training video (or written guide) for handlers: 15 min walkthrough of typical request

## 13. Deployment & Rollout

- [ ] 13.1 Create feature flag in `config/app.php`: `avg_requests.enabled` (default: false)

- [ ] 13.2 Run all database migrations on test environment

- [ ] 13.3 Load seed data (from design.md) for testing

- [ ] 13.4 Verify all background jobs are registered with Nextcloud Cron

- [ ] 13.5 Verify PKIoverheid certificate is deployed and accessible

- [ ] 13.6 Staging environment: run full integration test suite

- [ ] 13.7 Staging environment: manual testing by handlers, FG, admins

- [ ] 13.8 Production deployment:
  - Run migrations
  - Deploy code
  - Enable feature flag
  - Monitor: background job logs, error logs, performance metrics

## 14. Success Criteria Verification

- [ ] 14.1 Handler can complete a standard art. 15 request in <20 minutes of active work
- [ ] 14.2 Deadline escalation and breach detection work reliably (monitored over 1 week)
- [ ] 14.3 DPIA pattern detection fires correctly on test data (10+ similar requests)
- [ ] 14.4 PDF bundles are legally signed and verifiable by external tools
- [ ] 14.5 Secure download links expire correctly after 30 days
- [ ] 14.6 Evidence is pseudonymized 30 days after export
- [ ] 14.7 Dossier is deleted 5 years after resolution
- [ ] 14.8 FG reports: zero deadline breaches, 100% denial letters have AP reference
