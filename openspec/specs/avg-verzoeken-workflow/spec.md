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

The receiving capability SHALL track the 30-day legal deadline with automatic escalation and breach logging. Pipelinq SHALL NOT implement bespoke deadline tracking; the responsibility belongs to the canonical AVG / case-handling capability that owns the dossier lifecycle.

#### Scenario: Standard 30-day timer starts on submission

- **GIVEN** an AvgVerzoek is created
- **WHEN** the system calculates the deadline
- **THEN** `wettelijkeTermijnVerloopt` MUST be set to exactly 30 days later
- **AND** the handler dashboard MUST display "30 days remaining" in green

#### Scenario: 7-day advance reminder reaches the handler

- **GIVEN** a deadline is 7 days away
- **WHEN** the daily check job runs
- **THEN** the handler MUST receive a Nextcloud notification
- **AND** the request MUST remain in the green zone
- **AND** 7 days MUST pass before the next escalation check

#### Scenario: Team lead is escalated at 3 days remaining

- **GIVEN** a request is less than 72 hours from deadline and still unresolved
- **WHEN** the hourly deadline tracker job runs
- **THEN** the team lead MUST be added in CC to daily reminders
- **AND** a red warning flag MUST appear on the request card
- **AND** the request MUST appear in a dedicated "Overschrijding risico" queue
- **AND** a Nextcloud notification MUST alert the team lead

#### Scenario: Deadline breach is logged and the FG is notified

- **GIVEN** the deadline has passed and the request is still `status: "in-behandeling"`
- **WHEN** the breach check runs
- **THEN** a TermijnEvent MUST be created with `type: "termijn-overschreden"`
- **AND** the FG MUST receive a Nextcloud notification
- **AND** the request MUST appear in a dedicated "Te laat" worklist
- **AND** the audit trail MUST record the exact time of breach for AP compliance reporting

#### Scenario: Escalation skips completed requests

- **GIVEN** a request is resolved and marked `status: "afgerond"` before the deadline
- **WHEN** the escalation check runs after that
- **THEN** no escalation notification MUST be sent
- **AND** the request MUST not appear in any "at risk" queue
- **AND** the completion timestamp MUST be recorded in the audit trail

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

The receiving capability SHALL query OpenRegister, BRP, and OpenConnector sources to assemble evidence, with progress, timeouts, and deduplication tracking. Pipelinq SHALL NOT host a bespoke collector; the canonical owner is the AVG-dossier capability.

#### Scenario: Evidence collection starts for an Art. 15 request

- **GIVEN** an art. 15 request with a configured scope
- **WHEN** the handler clicks "Verzamel bewijs"
- **THEN** an async job MUST query OpenRegister schemas, BRP, and registered OpenConnector AVG-export-endpoints
- **AND** for each hit a BewijsItem MUST be created with source metadata, collection timestamp, legal basis, and export flag
- **AND** a progress indicator MUST be visible in the handler UI
- **AND** evidence status MUST be queryable via API

#### Scenario: Timeout on an external source is recorded

- **GIVEN** an OpenConnector source does not respond within 10 seconds
- **WHEN** the collection job hits the timeout
- **THEN** a BewijsItem MUST be created marking the source unreachable
- **AND** the handler MUST see a warning banner
- **AND** the handler MAY supplement manually
- **AND** the collection job MUST continue with other sources without blocking

#### Scenario: Identical evidence is deduplicated

- **GIVEN** BewijsItems are collected from multiple sources that return the same object
- **WHEN** the collector compares content hashes and object IDs
- **THEN** duplicates MUST be marked `gedupliceerd: true`
- **AND** the handler MUST be able to toggle "Show duplicates" in the Evidence tab
- **AND** the default behaviour MUST include the highest-quality version

#### Scenario: Collection fails gracefully on source error

- **GIVEN** an upstream source returns an HTTP 500
- **WHEN** the collection job encounters the error
- **THEN** the job MUST NOT crash
- **AND** a TermijnEvent MUST be created with `type: "collectie-fout"`
- **AND** the handler MUST see a partial-collection warning
- **AND** the handler MAY retry that source or proceed with partial results

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

The receiving capability SHALL retain dossiers for 5 years and pseudonymize evidence content 30 days after export. This belongs to the canonical retention/audit owner (**rekenkamer-audit-pack** + **docudesk**); pipelinq SHALL NOT host retention jobs for AVG dossiers.

#### Scenario: Resolved dossier is retained until the 5-year deadline

- **GIVEN** an AvgVerzoek is resolved
- **WHEN** the retention policy is applied
- **THEN** `retentieTot` MUST be set to 5 years from resolution
- **AND** the dossier MUST move to an "Archief" tab
- **AND** it MUST not appear in active worklists
- **AND** CRUD operations MUST be locked (read-only after resolution)
- **AND** the audit trail MUST remain immutable and queryable

#### Scenario: Evidence content is pseudonymized 30 days after export

- **GIVEN** an ExportBundle has been exported
- **WHEN** the pseudonymization job runs 30 days later
- **THEN** the `inhoudPreview` of each BewijsItem MUST be anonymized
- **AND** source metadata, legal basis, and timestamps MUST remain intact
- **AND** the original content MUST be cryptographically hashed for integrity verification
- **AND** the AvgVerzoek metadata itself MUST NOT be pseudonymized

#### Scenario: Dossier deletion is blocked before the 5-year window closes

- **GIVEN** a handler or FG attempts to delete within the retention window
- **WHEN** the delete action is triggered
- **THEN** the system MUST refuse with a retention-policy message
- **AND** only an FG with explicit audit justification MAY trigger early deletion
- **AND** the audit trail MUST log the deletion and justification permanently

#### Scenario: Dossier is auto-deleted after the 5-year deadline

- **GIVEN** the retention deadline has passed
- **WHEN** the cleanup job runs
- **THEN** the entire AvgVerzoek plus related entities MUST be deleted
- **AND** a final audit record MUST be created
- **AND** the deletion MUST be logged to the central SIEM
- **AND** the citizen MUST NOT be notified

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

