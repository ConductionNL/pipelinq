---
status: done
---

# avg-verzoeken-workflow Specification

## Purpose
Defines the handling of AVG (GDPR) data-subject requests — classification, 30-day deadline tracking, extensions, federated evidence collection, redaction, signed export bundles, denials, AP escalation, and retention. Every requirement is a handoff: pipelinq itself does not implement these workflows but delegates them to the canonical owners (docudesk, the AVG dossier capability, and rekenkamer-audit-pack), documenting the integration contracts those owners must satisfy.
## Requirements
### Requirement: REQ-AVG-001 — Automatic Article Classification at Intake [HANDOFF]

When a citizen submits an AVG request (via web form or manual handler registration), the receiving capability SHALL automatically classify the request article type based on the citizen's stated question or the handler's selection. Pipelinq SHALL NOT implement intake classification; this MUST be handled by the canonical AVG-intake capability (docudesk request lifecycle + privacy work).

#### Scenario: Web form radio selection routes to Article 15 (access)

- **GIVEN** a citizen views the AVG web form on the canonical intake capability
- **WHEN** they select "Ik wil weten welke gegevens jullie van mij hebben"
- **THEN** an AvgVerzoek MUST be created with `artikel: "art-15-inzage"` by the receiving capability
- **AND** the legal deadline MUST be calculated as 30 days from submission
- **AND** the deadline MUST be displayed in the handler dashboard with green color (>7 days remaining)

#### Scenario: Handler ambiguity triggers multi-article choice

- **GIVEN** a handler enters free text covering correction and erasure
- **WHEN** they confirm submission
- **THEN** the receiving capability MUST present a multi-article choice
- **AND** the handler MUST be able to select multiple articles and create linked AvgVerzoeken
- **AND** each sub-request MUST have its own deadline and evidence collection scope

#### Scenario: Non-AVG complaint is rejected from the AVG queue

- **GIVEN** a handler reviews a free-text submission that is not GDPR-based
- **WHEN** they mark it "Not GDPR-based"
- **THEN** the AvgVerzoek MUST be reclassified to `status: "inactief"` and removed from AVG queues
- **AND** it MUST be moved to the general request stream for handling as a regular complaint
- **AND** no legal deadline MUST be calculated

#### Scenario: Article 20 portability requests auto-populate scope

- **GIVEN** a citizen requests their data in machine-readable format
- **WHEN** the form is submitted
- **THEN** the receiving capability MUST classify the request as `artikel: "art-20-portabiliteit"`
- **AND** MUST auto-populate the scope to include all accessible data sources the organization has declared

### Requirement: REQ-AVG-002 — 30-Day Legal Deadline Tracking with Escalation [HANDOFF]

The receiving capability SHALL compute the legal response deadline as the EU art-12(3) term —
**one month** from receipt, extendable once by **two further months** — via OpenRegister's
canonical `DataSubjectDeadline`, NOT a bespoke NL 30-day/60-day approximation. Pipelinq SHALL
keep the Dutch operational escalation chain (7-day advance reminder, <72h team-lead escalation,
breach logging) that OpenRegister does not own, computing it FROM the OR-derived deadline, with
each milestone idempotent via an existing-TermijnEvent guard.

#### Scenario: One-month timer starts on submission

- **GIVEN** an AvgVerzoek is created
- **WHEN** the system calculates the deadline
- **THEN** `wettelijkeTermijnVerloopt` MUST be set to exactly one month later (OR `DataSubjectDeadline::computeDueAt`), normalised to end-of-day
- **AND** the handler dashboard MUST display the request in the green zone
- `@e2e exclude` server-authoritative deadline maths verified by DeadlineServiceTest + live :8080 PHP harness (received 2026-06-23 → due 2026-07-23)

#### Scenario: Single extension adds two months

- **GIVEN** an open request inside its deadline
- **WHEN** a justified extension is granted
- **THEN** the new deadline MUST be the base (received + 1 month) plus two further months (received + 3 months total)
- **AND** a second extension MUST be refused
- `@e2e exclude` server-authoritative extension maths verified by ExtensionServiceTest (intake 2026-04-08 → extended 2026-07-08) + live harness

#### Scenario: Escalation chain fires from the EU deadline

- **GIVEN** a request whose EU deadline is 7 days away, then <72h away, then passed
- **WHEN** the deadline jobs run
- **THEN** a 7-day reminder, then a <72h team-lead escalation, then a `termijn-overschreden` breach TermijnEvent + FG notification MUST each fire exactly once, computed from `wettelijkeTermijnVerloopt`
- **AND** repeated job runs MUST NOT double-notify (existing-TermijnEvent idempotency)
- `@e2e exclude` job-driven escalation chain verified by DeadlineTrackerServiceTest

### Requirement: REQ-AVG-003 — 60-Day Extension with Mandatory Justification [HANDOFF]

The receiving capability SHALL allow handlers to request a 60-day extension with mandatory justification. Pipelinq SHALL NOT own this workflow.

#### Scenario: Handler extends deadline on day 25 with justification

- **GIVEN** a handler decides extension is needed on day 25
- **WHEN** they click "Verleng termijn"
- **THEN** a modal MUST require a justified reason (min. 30 characters)
- **AND** on save `verlengdMet: 60` and `verlengingsgrond` MUST be stored
- **AND** the new deadline MUST be 60 more days
- **AND** a Nextcloud Mail MUST auto-generate to the citizen with the new deadline
- **AND** the handler MUST manually review and approve the email before sending (4-eyes)

#### Scenario: Extension is blocked after day 30

- **GIVEN** a handler attempts extension after the deadline has already passed
- **WHEN** they click "Verleng termijn"
- **THEN** the receiving capability MUST refuse with an explanatory error
- **AND** the request MUST not be extended
- **AND** an audit entry MUST log the attempted late extension

#### Scenario: Only one extension is allowed

- **GIVEN** a request has already been extended once
- **WHEN** the handler attempts a second extension
- **THEN** the "Verleng termijn" action MUST be disabled
- **AND** the UI MUST explain that only one extension is permitted per AVG Art. 12(3)
- **AND** the handler MAY mark the request for escalation to the legal department

### Requirement: REQ-AVG-004 — Federated Evidence Collection from Multiple Sources [HANDOFF]

The receiving capability SHALL discover the data subject's objects through OpenRegister's
canonical NER-index discovery (`DataSubjectRequestService::findSubjectData`, RBAC + tenant
scoped), NOT a bespoke `bsn`-equality `findAll` filter. The OpenConnector federated-source
collection, BewijsItem packaging, scope overlay, and content-hash deduplication remain the
pipelinq app overlay on top of that discovery.

#### Scenario: Discovery uses OR's NER index

- **GIVEN** an art-15 request with a verified BSN
- **WHEN** the handler triggers evidence collection
- **THEN** the app MUST call `findSubjectData(subjectId)` so every object the OR NER index ties to the subject (BSN / email / name) is discovered
- **AND** each in-scope envelope MUST become a BewijsItem with source metadata, the matched GdprEntity category, collection timestamp, legal basis, and export flag
- **AND** the app MUST NOT issue a single-column `bsn` equality filter for discovery
- `@e2e exclude` discovery boundary verified by EvidenceCollectionServiceTest (`testSubjectDiscoveryUsesOrNerIndex`) + live findSubjectData shape check

#### Scenario: External source timeout and dedup are unchanged

- **GIVEN** an OpenConnector source times out and another returns a duplicate object
- **WHEN** collection runs
- **THEN** the unreachable source MUST yield a `bron-onbereikbaar` BewijsItem + `collectie-fout` TermijnEvent without aborting the run
- **AND** identical content MUST be flagged `gedupliceerd` and excluded from export
- `@e2e exclude` federated overlay behaviour unchanged; verified by EvidenceCollectionServiceTest

### Requirement: REQ-AVG-005 — Data Export Bundle with Legal Signature [HANDOFF]

The receiving capability SHALL produce a legally binding export bundle (PDF + JSON) with PAdES-LTV signing. This responsibility is already owned by **docudesk** (render + signing) and **rekenkamer-audit-pack** (signed evidence bundle pattern); pipelinq SHALL NOT re-implement bundle generation.

#### Scenario: PDF bundle is rendered through docudesk

- **GIVEN** all BewijsItems are finalized with no pending redactions
- **WHEN** the handler clicks "Genereer bundle"
- **THEN** the receiving capability MUST call the docudesk render API with the org branding template, ToC by category, and per-item headers
- **AND** docudesk MUST return a PDF binary
- **AND** a parallel JSON export MUST be generated with identical structure
- **AND** both PDF and JSON MUST be stored in the ExportBundle record

#### Scenario: Bundle is PAdES-LTV signed with PKIoverheid certificate

- **GIVEN** the PDF and JSON are ready for export
- **WHEN** the handler authorizes the bundle
- **THEN** PAdES-LTV signing MUST be applied with the municipality's PKIoverheid certificate and a Dutch TSA timestamp
- **AND** the SHA-256 hash MUST be computed from the finalized PDF
- **AND** the ExportBundle record MUST store the signature type and hash
- **AND** the signature MUST be verifiable with standard PDF tools

#### Scenario: Secure download link is generated for the citizen

- **GIVEN** the bundle is signed and ready
- **WHEN** the handler clicks "Verzend bundle naar verzoeker"
- **THEN** a time-limited (30 day) one-time-use download link MUST be generated
- **AND** the ExportBundle MUST store the encrypted token and expiry
- **AND** the citizen MUST be notified via Nextcloud Mail or Berichtenbox
- **AND** the token MUST NOT appear in plain text in logs

#### Scenario: Download link expires after 30 days

- **GIVEN** a download link was created with a 30-day validity
- **WHEN** the citizen attempts to download after expiry
- **THEN** the system MUST return HTTP 403 with an explanatory message
- **AND** a new link MAY be generated on request by the handler
- **AND** both link generations MUST be logged in the audit trail

### Requirement: REQ-AVG-006 — Redaction Tool for Third-Party Data Protection [HANDOFF]

The receiving capability SHALL provide a redaction tool for masking third-party data prior to export. This belongs to the canonical AVG dossier capability and overlaps with privacy / FG tooling already planned elsewhere; pipelinq SHALL NOT host a parallel redaction editor.

#### Scenario: Third-party field is visually redacted

- **GIVEN** a BewijsItem contains a third-party name
- **WHEN** the handler opens the Redactie tab and redacts that field
- **THEN** a RedactieActie MUST be created storing the field path, before/after values, ground, redactor, and timestamp
- **AND** the preview MUST show the redacted version immediately
- **AND** the handler MAY undo or edit the redaction until the bundle is finalized

#### Scenario: Before/after comparison enables 4-eyes control

- **GIVEN** redactions have been applied to multiple items
- **WHEN** the handler opens the redaction summary
- **THEN** a side-by-side panel MUST list each redaction with field path, before, after, reason, redactor, and timestamp
- **AND** an approval checkbox per redaction MUST be present
- **AND** a sign-off button MUST authorize all redactions for the bundle
- **AND** bundle generation MUST be blocked until all redactions are approved

#### Scenario: Redaction on the citizen's own data is blocked

- **GIVEN** a field contains the citizen's own name
- **WHEN** the handler attempts to redact it
- **THEN** the system MUST block the action with an error referencing Art. 23
- **AND** the field MUST display a lock icon with explanation
- **AND** the handler MUST either proceed without redaction or create a Weigering

#### Scenario: Redaction audit trail is exported with the bundle

- **GIVEN** redactions are finalized on the export bundle
- **WHEN** the bundle is exported as JSON
- **THEN** the JSON MUST include a root-level `redacties` array with full audit metadata
- **AND** this metadata MUST be signed as part of the PAdES-LTV bundle
- **AND** the citizen and AP MUST be able to verify the audit trail

### Requirement: REQ-AVG-007 — Denial with GDPR Article 23 Grounds [HANDOFF]

The receiving capability SHALL allow handlers to record explicit, justified denials under GDPR Article 23. Pipelinq SHALL NOT host the Weigering workflow.

#### Scenario: Complete denial is recorded under Art. 23

- **GIVEN** a request relates to an ongoing criminal investigation
- **WHEN** the handler clicks "Weiger verzoek"
- **THEN** a form MUST capture denial scope, ground, and a min. 100-character motivation
- **AND** on save a Weigering MUST be created with the chosen ground, motivation, signer, and timestamp

#### Scenario: Partial denial captures per-scope reasoning

- **GIVEN** a multi-scope request where only some scopes can be denied
- **WHEN** the handler selects "gedeeltelijk geweigerd"
- **THEN** the handler MUST be able to record `geweigerdeOnderdelen` and per-scope reasoning
- **AND** the bundle MUST include full access for accepted scopes and a per-scope refusal page for denied scopes

#### Scenario: AP complaint reference is mandatory in the denial letter

- **GIVEN** a Weigering is being drafted
- **WHEN** the handler prepares the final denial letter
- **THEN** the system MUST mandate the AP contact / appeal text block
- **AND** denial letter generation MUST be blocked if this text is missing
- **AND** on PDF export the AP contact info MUST be clickable

#### Scenario: Appeal / rethink overrules the original Weigering

- **GIVEN** a Weigering has been issued and the citizen has appealed
- **WHEN** the FG re-reviews the grounds
- **THEN** the FG MAY mark the original Weigering as `status: "overschreven"`
- **AND** MAY create a new Weigering with updated grounds or convert to full access
- **AND** both Weigeringen MUST be retained in the audit trail with timestamps

### Requirement: REQ-AVG-008 — AP Escalation with Complete Dossier Export [HANDOFF]

The receiving capability SHALL export a complete dossier package on AP escalation. This is already the pattern used by **rekenkamer-audit-pack** (signed ZIP, manifest, audit trail); pipelinq SHALL NOT duplicate it.

#### Scenario: AP complaint triggers a dossier ZIP package

- **GIVEN** the FG marks the request with `dpiaFlag: "ap-klacht"`
- **WHEN** the AP-escalation job runs
- **THEN** the system MUST assemble a complete dossier ZIP containing the request record, termijn events, bewijs items, redactie acties, export bundle, weigeringen, correspondentie, and audit trail
- **AND** the ZIP MUST be signed with the organization's digital signature
- **AND** a manifest MUST list every document with timestamps and hashes

#### Scenario: AP annual report aggregates anonymized statistics

- **GIVEN** the AP requests annual statistics
- **WHEN** the FG runs "Generate AP Annual Report"
- **THEN** the system MUST aggregate anonymized counts by article, average processing times, denial percentage and grounds, deadline breaches, and extensions
- **AND** export in CSV suitable for the AP's prescribed template
- **AND** ensure no citizen data is exposed (only statistics)

### Requirement: REQ-AVG-009 — 5-Year Dossier Retention with Evidence Pseudonymization [HANDOFF]

The receiving capability SHALL retain dossiers for 5 years (an app-owned policy OR does not own)
and SHALL scrub evidence PII on the 30-day post-export window. Right-to-be-forgotten erasure SHALL
delegate to OpenRegister's legal-hold-aware field-level pseudonymise erasure
(`DataSubjectRequestService::erase(..., 'pseudonymise')`), which RETAINS the owning row and skips
objects under legal hold / immutability — preserving the NL Boekhoudplicht 7-year booking
retention. The cached-evidence scrub aligns on OR's `[erased]` token.

#### Scenario: Erasure preserves the Boekhoudplicht booking row

- **GIVEN** a right-to-be-forgotten request for a customer with booking records, one under an active 7-year Boekhoudplicht legal hold
- **WHEN** the app erases the customer's data via OR
- **THEN** OR MUST overwrite the matching PII values with the `[erased]` token and RETAIN every row
- **AND** the held booking MUST be reported in the `held` bucket and never deleted nor mutated
- **AND** the erasure MUST NOT use `whole-object` (row-delete) mode
- `@e2e exclude` retention invariant verified by DataDeletionServiceTest (`testHeldBookingRowSurvivesErasure`) + live :8080 RetentionService legal-hold guard proof

#### Scenario: 5-year dossier retention and 30-day evidence window stay app-owned

- **GIVEN** archived dossiers with a 5-year `retentieTot` and evidence items past the 30-day window
- **WHEN** the retention pass runs
- **THEN** the dossier cut-off MUST be computed from the app's `retentieTot` (5-year cascade delete) and the evidence cut-off from `verzameldOp + N days`
- **AND** an over-window evidence item's `inhoudPreview` MUST be replaced with OR's `[erased]` token while its source metadata stays intact
- `@e2e exclude` app-owned retention schedule verified by RetentionServiceTest

### Requirement: REQ-AVG-010 — DPIA Pattern Detection and FG Notification [HANDOFF]

The receiving capability SHALL detect patterns of similar requests that suggest systemic data processing issues. This is already the scope of the broader **privacy / DPIA** initiative; pipelinq SHALL NOT host the detector.

#### Scenario: Automatic DPIA flag fires on 10+ similar requests

- **GIVEN** 10 or more art. 17 requests with similar scope arrive within 30 days
- **WHEN** the weekly DPIA-pattern-detection job runs
- **THEN** the system MUST group by article + scope + word similarity
- **AND** MUST set `dpiaFlag: true` on all matching requests
- **AND** MUST notify the FG with a DPIA-review recommendation
- **AND** MUST mark the requests with a DPIA badge in the dashboard

#### Scenario: Handler manually flags a request for DPIA review

- **GIVEN** a single request that suggests an unusual pattern
- **WHEN** the handler ticks "DPIA-aandacht nodig"
- **THEN** `dpiaFlag: true` MUST be set on that request
- **AND** the FG MUST receive a notification with the handler's reason
- **AND** the FG MAY link this request to others or initiate a formal DPIA

#### Scenario: DPIA flag creates a linked Procest improvement task

- **GIVEN** a DPIA flag is set and the FG decides a DPIA is warranted
- **WHEN** the FG clicks "Create Procest Improvement"
- **THEN** a new Procest item MUST be created with category "gegevensbescherming"
- **AND** the item MUST be linked back to the originating AvgVerzoeken
- **AND** the request cards MUST display the linked Procest reference

#### Scenario: Monthly DPIA summary is published to the FG dashboard

- **GIVEN** it is the first of the month
- **WHEN** the FG opens the dashboard
- **THEN** a "DPIA Summary" card MUST show flagged requests, top patterns, linked Procest item status, and recommended actions
- **AND** the FG MAY export this as a report

### Requirement: REQ-AVG-011 — BSN Validation at Intake [HANDOFF — DEPENDENCY]

The receiving capability SHALL verify the citizen's BSN via BRP at intake. This depends on the BSN-validation capability and is out of pipelinq scope.

#### Scenario: Valid BSN passes BRP lookup

- **GIVEN** a citizen submits the web form with a valid BSN and name
- **WHEN** the form is submitted
- **THEN** the BSN validation capability MUST be called with the binding "handling AVG request art. {X}"
- **AND** on match `verzoekerBsnGeverifieerd: true` MUST be set on the AvgVerzoek
- **AND** the request MUST proceed to intake processing

#### Scenario: Invalid or unmatched BSN blocks creation

- **GIVEN** a citizen enters an invalid BSN
- **WHEN** the form is submitted
- **THEN** the system MUST display an error and refuse to create the AvgVerzoek
- **AND** the citizen MUST be offered a manual-request alternative with additional identity verification

### Requirement: REQ-AVG-012 — OpenConnector Integration for External Source Queries [HANDOFF — INTEGRATION]

The receiving capability SHALL integrate with external systems via OpenConnector for AVG export queries. The integration contract is documented for the receiving owner; pipelinq SHALL NOT host the connector glue for AVG.

#### Scenario: OpenConnector source exposes an AVG-export endpoint

- **GIVEN** an external system is registered in pipelinq via OpenConnector
- **THEN** that system MUST expose `GET /avg-export?bsn={bsn}&scope={scope}&artikel={artikel}`
- **AND** MUST return a JSON response with items, category, content, and legal basis
- **AND** MUST respond within 10 seconds
- **AND** the integration MUST handle timeouts gracefully
- **AND** the organization is responsible for implementing this endpoint on the external system

### Requirement: REQ-AVG-013 — Email and Notification Templates [HANDOFF — CONFIGURATION]

The receiving capability SHALL allow organizations to customize email templates for AVG citizen communication, with template versioning so old requests render consistently. Pipelinq SHALL NOT host the template editor.

#### Scenario: Receipt confirmation email is customizable

- **GIVEN** the admin opens Settings → AVG Request Templates
- **THEN** editable fields MUST include subject and body for receipt confirmation, extension notification, and denial letter
- **AND** placeholder documentation MUST explain the required fields (especially the AP complaint reference)
- **AND** a preview MUST render the email with sample data
- **AND** template versions MUST be retained so historical requests render their templates consistently (per docudesk template versioning)

### Requirement: REQ-AVG-014 — OpenRegister Compliance-Subsystem Consumption Boundary

The AVG workflow SHALL fulfil data-subject rights through OpenRegister's canonical,
RBAC + tenant scoped GDPR capability — `DataSubjectRequestService` (`findSubjectData` /
`assembleAccessExport` / `erase`) and `DataSubjectDeadline` — consumed via the `OrGdprBridge`
adapter. This SUPERSEDES the earlier boundary that kept subject matching, deadline maths, and
erasure semantics in the app: the owner has decided OR's EU-generic mechanics (one-month
deadline, NER discovery, legal-hold-aware pseudonymise) are the correct floor. The app SHALL
still NOT call the administrator-gated `DsarService` (which bypasses RBAC and soft-deletes whole
objects); it consumes the non-admin `DataSubjectRequestService` counterpart, which is RBAC +
tenant scoped and erases via field-level pseudonymise-and-keep. The bridge SHALL degrade to a
safe no-op when OpenRegister is absent, and SHALL never log the subject identifier value.

**Feature tier**: MVP

#### Scenario: Subject discovery and erasure go through the canonical RBAC-scoped service

- **GIVEN** an AVG handler (not necessarily an administrator) fulfilling a request for a data subject
- **WHEN** the app discovers or erases the subject's objects
- **THEN** it MUST use `DataSubjectRequestService::findSubjectData` / `erase` via `OrGdprBridge`, which is RBAC + tenant scoped (the caller only reaches objects it may read/mutate)
- **AND** it MUST NOT call the administrator-gated `DsarService`, which bypasses RBAC and soft-deletes whole objects
- `@e2e exclude` consumption boundary verified by OrGdprBridge live resolution on :8080 (`isAvailable: true`) + DataDeletionServiceTest

#### Scenario: Deadline maths is the EU canonical computation

- **GIVEN** a request received on a given date
- **WHEN** the app computes the legal deadline
- **THEN** it MUST use `DataSubjectDeadline::computeDueAt` (+1 month) and `extend` (+2 months) via `OrGdprBridge`, NOT a local day-count
- `@e2e exclude` deadline delegation verified by DeadlineServiceTest + live harness

#### Scenario: Bridge degrades safely without OpenRegister

- **GIVEN** OpenRegister is not installed
- **WHEN** the app invokes any GDPR leg through `OrGdprBridge`
- **THEN** discovery/export/erase MUST return safe empty results and the deadline MUST fall back to the same one-month/two-month maths locally
- **AND** the subject identifier value MUST NOT be written to any log
- `@e2e exclude` graceful-degradation + no-BSN-logging verified by OrGdprBridge code review + DataDeletionServiceTest (logs only counts)

