---
status: draft
---

# Spec: AVG-verzoeken Workflow

## Purpose

Implement a complete workflow for handling GDPR (AVG) requests under Articles 15, 16, 17, 18, and 20 within Dutch legal deadlines (30 days, extendable to 60 days with justification). The system must track deadlines, collect evidence from federated sources, redact third-party information, export legally signed bundles, record denials with explicit grounds, and flag systemic data processing issues for privacy impact assessment.

---

## REQ-AVG-001: Automatic Article Classification at Intake [MVP]

When a citizen submits an AVG request (via web form or manual handler registration), the system MUST automatically classify the request article type based on the citizen's stated question or the handler's selection.

### Scenario: Web form radio selection → Article 15 (access)

- GIVEN a citizen views the AVG web form
- WHEN they select "Ik wil weten welke gegevens jullie van mij hebben" (I want to know what data you have about me)
- THEN an AvgVerzoek MUST be created with `artikel: "art-15-inzage"`
- AND the legal deadline MUST be calculated as 30 days from submission: `wettelijkeTermijnVerloopt: 2026-05-08T23:59:59+02:00`
- AND the deadline MUST be displayed in the handler dashboard with green color (>7 days remaining)

### Scenario: Handler ambiguity → Multi-article choice

- GIVEN a handler enters free text: "Ik wil mijn adres corrigeren en mijn naam uit het systeem verwijderen"
- WHEN they confirm submission
- THEN the system MUST present a choice: "This appears to cover Art. 16 (correction) and Art. 17 (erasure). Select one or create sub-requests for both:"
- AND the handler MUST be able to select both articles and create two linked AvgVerzoeken with the same citizen
- AND each sub-request MUST have its own deadline and evidence collection scope

### Scenario: Non-AVG complaint rejection

- GIVEN a handler reviews a free-text submission: "Your service was slow"
- WHEN they mark it "Not GDPR-based" via a checkbox
- THEN the AvgVerzoek MUST be reclassified to `status: "inactief"` and removed from AVG queues
- AND it MUST be moved to the general request stream for handling as a regular complaint
- AND no legal deadline MUST be calculated

### Scenario: Article 20 (portability) scope validation

- GIVEN a citizen requests "mijn gegevens geporteerd in machine-leesbaar formaat" (my data ported in machine-readable format)
- WHEN the form is submitted
- THEN the system MUST classify as `artikel: "art-20-portabiliteit"`
- AND MUST auto-populate the scope to include all accessible data sources the organization has declared

---

## REQ-AVG-002: 30-Day Legal Deadline Tracking with Escalation [MVP]

The system MUST track the 30-day legal deadline with automatic escalation and breach logging.

### Scenario: Standard 30-day timer starts on submission

- GIVEN an AvgVerzoek is created at 2026-04-08T11:14:00+02:00
- WHEN the system calculates the deadline
- THEN `wettelijkeTermijnVerloopt` MUST be set to exactly 30 days later: 2026-05-08T23:59:59+02:00
- AND the handler dashboard MUST display "30 days remaining" in green

### Scenario: 7-day advance reminder sent to handler

- GIVEN a deadline is 7 days away (2026-05-01T23:59:59+02:00)
- WHEN the daily check job runs
- THEN the handler MUST receive a Nextcloud notification: "AVG request {kenmerk} expires in 7 days"
- AND the request MUST remain in the green zone
- AND 7 days MUST pass before the next escalation check

### Scenario: Escalation to team lead at 3 days remaining

- GIVEN a request is <72 hours from deadline and still unresolved
- WHEN the hourly deadline tracker job runs
- THEN the team lead MUST be added in CC to daily reminders
- AND a red warning flag MUST appear on the request card in the dashboard
- AND the request MUST appear in a separate "Overschrijding risico" (deadline risk) queue
- AND a Nextcloud notification MUST alert the team lead: "AVG request {kenmerk} at risk of deadline breach"

### Scenario: Deadline breach logged and FG notified

- GIVEN the deadline has passed (now 2026-05-09T00:00:01+02:00) and the request is still `status: "in-behandeling"`
- WHEN midnight passes and the breach check runs
- THEN a TermijnEvent MUST be created with `type: "termijn-overschreden"`, `status: "geschonden"`
- AND the FG MUST receive a Nextcloud notification: "AVG request {kenmerk} missed legal deadline. Immediate action required."
- AND the request MUST appear in a dedicated "Te laat" (late) worklist
- AND the audit trail MUST record the exact time of breach for AP compliance reporting

### Scenario: Escalation does not apply to completed requests

- GIVEN a request is resolved and marked `status: "afgerond"` on 2026-05-07 (1 day before deadline)
- WHEN the escalation check runs on 2026-05-08 and 2026-05-09
- THEN no escalation notification MUST be sent
- AND the request MUST not appear in any "at risk" queue
- AND the completion timestamp MUST be recorded in the audit trail

---

## REQ-AVG-003: 60-Day Extension with Mandatory Justification [MVP]

The handler MAY request a 60-day extension (doubling the deadline) if justified, but MUST include a reason and MUST notify the citizen by day 30.

### Scenario: Handler extends deadline on day 25 with justification

- GIVEN a handler decides extension is needed on day 25 (within the 30-day window)
- WHEN they click "Verleng termijn" (extend deadline)
- THEN a modal MUST appear with a required text field: "Reason for extension (min. 30 characters):"
- AND the system MUST accept only reasons that include one of: "complexiteit", "aantal verzoeken", "technische beperking", "bron-onbereikbaar", "wacht op externe partij"
- AND on save, `verlengdMet: 60` and `verlengingsgrond: "{reason}"` MUST be stored
- AND the new deadline MUST be calculated: 60 more days
- AND a Nextcloud Mail MUST auto-generate to the citizen: "Your AVG request has been granted a 60-day extension due to: {reason}. New deadline: 2026-07-07T23:59:59+02:00"
- AND the handler MUST manually review and approve the email before sending (4-eyes control)

### Scenario: Extension blocked after day 30

- GIVEN a handler attempts extension on day 31 (deadline already passed)
- WHEN they click "Verleng termijn"
- THEN the system MUST display error: "Extension MUST be communicated by day 30 per GDPR Art. 12(3). This deadline has passed. Contact FG for escalation."
- AND the request MUST not be extended
- AND an audit entry MUST log the attempted late extension for compliance

### Scenario: Only one extension allowed

- GIVEN a request has already been extended once (stored as `verlengdMet: 60`)
- WHEN the handler attempts a second extension
- THEN the "Verleng termijn" button MUST be disabled
- AND on hover, tooltip MUST explain: "Only one extension is permitted per AVG Art. 12(3). For further delay, escalate to FG/Legal."
- AND the handler may mark the request for escalation to the legal department

---

## REQ-AVG-004: Federated Evidence Collection from Multiple Sources [MVP]

When a handler initiates evidence collection, the system MUST query OpenRegister, BRP, OpenConnector sources, and track collection progress, timeouts, and deduplication.

### Scenario: Evidence collection started for Art. 15 (access)

- GIVEN an art. 15 request with `scope: ["parkeervergunningen", "communicatie"]`
- WHEN the handler clicks "Verzamel bewijs" (collect evidence)
- THEN an async job MUST start that queries:
  1. OpenRegister schema "parkeervergunningen" filtered by BSN = {citizen_bsn}
  2. OpenRegister schema "communicatie" filtered by BSN = {citizen_bsn}
  3. BRP (via BSN validation capability)
  4. All OpenConnector sources registered with "AVG-export-endpoint"
- AND for each hit, a BewijsItem MUST be created with:
  - `bronApp: "openregister"` or `"brp"` or `"openconnector"`
  - `bronRegister: "{schema_name}"` and `bronObject: "{object_uuid}"`
  - `verzameldOp: {timestamp}` (exact collection time)
  - `rechtsgrond: "{legal_basis}"` (from source metadata or predefined per app)
  - `ingesloten_in_export: true`
- AND a progress indicator MUST show in the handler UI: "Querying sources... 34 items found so far"
- AND a status MUST be queryable via API: `GET /api/v2/avg-verzoeken/{id}/evidence-status` returning `{ collected: 34, pending: 2, failed: 0 }`

### Scenario: Timeout on external source

- GIVEN OpenConnector source "external-crm" does not respond within 10 seconds
- WHEN the collection job hits the timeout
- THEN a BewijsItem MUST be created with:
  - `bronApp: "openconnector"`
  - `verzameldOp: null`
  - `categorie: "bron-onbereikbaar"`
  - `inhoudPreview: "External CRM API did not respond within 10 seconds. Manual supplementation may be required."`
- AND the handler MUST see a warning banner: "⚠ External CRM did not respond. Check if supplementary data is needed."
- AND the handler may manually add BewijsItems by pasting JSON/CSV or selecting objects from a fallback manual interface
- AND the collection job MUST continue with other sources without blocking

### Scenario: Deduplication of identical evidence

- GIVEN BewijsItems are collected from both OpenRegister and an OpenConnector mirror
- WHEN both contain the same "parkeervergunning-2024-09-882" object
- THEN the system MUST detect identity via content-hash + objectId matching
- AND the duplicate MUST be marked with `gedupliceerd: true`
- AND the handler's "Evidence" tab MUST show the pair with a "Show duplicates" toggle
- AND the handler may choose which version to include in the export (or both, with a note)
- AND the default behavior MUST be to include the highest-quality version (most complete fields)

### Scenario: Collection fails gracefully on source error

- GIVEN OpenRegister returns HTTP 500 for the parkeervergunningen query
- WHEN the collection job encounters the error
- THEN the job MUST NOT crash
- AND a TermijnEvent MUST be created with `type: "collectie-fout"`, `details: "OpenRegister parkeervergunningen API error: 500"`
- AND the handler MUST see: "⚠ Partial collection: parkeervergunningen search failed. Retry or supplement manually."
- AND the handler may trigger a retry of just that source, or proceed with partial results

---

## REQ-AVG-005: Data Export Bundle with Legal Signature (PDF + JSON) [MVP]

When the handler completes evidence gathering and redaction, they MUST generate a legally binding export bundle.

### Scenario: PDF bundle generation with DocuDesk

- GIVEN all BewijsItems are finalized (no pending redactions)
- WHEN the handler clicks "Genereer bundle"
- THEN Pipelinq MUST call DocuDesk render API with:
  - Organization branding template
  - Table of contents grouped by `categorie` (e.g., "Vergunningen", "Communicatie", "Financiën")
  - Per-item page header: item ID, source app, legal basis, redaction status
  - Item content (redacted if applicable)
  - Closing statement signed by handler with name, date, title
- AND DocuDesk MUST return a PDF binary file
- AND a parallel JSON export MUST be generated with identical structure:
  ```json
  {
    "kenmerk": "AVG-2026-0034",
    "samengesteldOp": "2026-04-22T16:30:00+02:00",
    "samengesteldDoor": "medewerker:s.jansen@gemeente-zeist.nl",
    "bewijsItems": [
      { "id": "...", "categorie": "Vergunningen", "inhoud": {...}, "redacties": [...] }
    ],
    "metadata": { "totaalItems": 47, "sha256": "8d4e1f..." }
  }
  ```
- AND both PDF and JSON MUST be stored in the ExportBundle record

### Scenario: PAdES-LTV cryptographic signing

- GIVEN the PDF and JSON are ready for export
- WHEN the handler authorizes the bundle
- THEN Pipelinq MUST obtain the municipality's PKIoverheid certificate from config
- AND MUST invoke PAdES-LTV signing (ETSI EN 319 142 standard):
  - Embeds Long-Term Validation (LTV) data so signature remains valid >30 years
  - Includes timestamp from Dutch TSA
  - Creates audit trail entry in the PDF
- AND SHA-256 hash MUST be computed from the finalized PDF
- AND the ExportBundle record MUST store:
  - `ondertekend: true`
  - `ondertekeningsType: "PAdES-LTV"`
  - `sha256: "{hash}"`
- AND the citizen and AP may verify the signature using standard PDF tools (e.g., Adobe Reader, open-source validators)

### Scenario: Secure download link generation

- GIVEN the bundle is signed and ready
- WHEN the handler clicks "Verzend bundle naar verzoeker"
- THEN the system MUST generate a time-limited download link:
  - Token: random UUID4
  - Validity: 30 days from generation
  - One-time use (disabled after first download)
- AND the ExportBundle MUST store:
  - `downloadCode: "{encrypted_token}"`
  - `downloadVerloopt: "2026-05-22T17:00:00+02:00"`
  - `uitgeleverdVia: "veilige-download-link"` or `"berichtenbox"`
- AND the system MUST send the citizen an email via Nextcloud Mail with:
  - Subject: "Uw AVG-verzoek (referentie: AVG-2026-0034) — Gegevensexport"
  - Body: "Download link valid for 30 days: https://gemeente-zeist.nl/avg/download/{token}"
  - Link MUST NOT embed the token in plain text; MUST use HTTPS with secure session validation
- AND a copy MUST be logged in the audit trail without exposing the token
- AND alternative: if organization uses Berichtenbox, the link MUST be sent via that encrypted channel instead

### Scenario: Download link expires after 30 days

- GIVEN a download link was created on 2026-04-22 with validity until 2026-05-22
- WHEN a citizen attempts to download on 2026-05-23
- THEN the system MUST return HTTP 403 Forbidden: "Download link has expired. Contact the organization to request a new link."
- AND a new download link MAY be generated on request by the handler
- AND both link generations MUST be logged in the audit trail

---

## REQ-AVG-006: Redaction Tool for Third-Party Data Protection [MVP]

Before export, the handler MUST be able to mask third-party information to protect their rights.

### Scenario: Visual redaction of third-party name

- GIVEN a BewijsItem contains: `{ "handhaver": { "naam": "J.C. de Boer", "badge": "HV-2024-001" } }`
- WHEN the handler opens the "Redactie" tab and selects this item
- THEN the system MUST display the JSON in an editable preview
- AND the handler may click on the `"naam"` field and select "Redigeer veld" (redact field)
- THEN a RedactieActie MUST be created storing:
  - `veldpad: "$.handhaver.naam"`
  - `voorWaarde: "J.C. de Boer"`
  - `naWaarde: "[redacted: third-party employee — GDPR Art. 41]"` (i18n-ed)
  - `grond: "bescherming-rechten-derden"`
  - `uitgevoerdDoor: "medewerker:s.jansen@gemeente-zeist.nl"`
  - `uitgevoerdOp: "{timestamp}"`
- AND the preview MUST show the redacted version immediately
- AND the handler may undo or edit the redaction until bundle is finalized

### Scenario: Before/after comparison for 4-eyes control

- GIVEN redactions have been applied to multiple items
- WHEN the handler clicks "Bekijk redactie-overzicht" (view redaction summary)
- THEN a side-by-side panel MUST appear listing all redactions:
  - Item ID
  - Field path
  - Before: `"J.C. de Boer"`
  - After: `"[redacted: third-party employee — GDPR Art. 41]"`
  - Reason: `bescherming-rechten-derden`
  - Redactor name and time
- AND a checkbox for each redaction MUST allow the handler to approve/reject
- AND a "Sign off" button MUST be present for the handler to authorize all redactions for this bundle
- AND the system MUST NOT allow bundle generation until all redactions are approved

### Scenario: Redaction blocked on citizen's own data

- GIVEN a BewijsItem field contains `{ "verzoeker": { "naam": "M.W. van der Berg", ... } }`
- WHEN the handler attempts to redact the verzoeker's own name
- THEN the system MUST block the action with error: "This is the citizen's own data. Redacting it would deny their right of access. Redaction requires AVG Art. 23 documentation — create a Denial instead."
- AND the field MUST show a lock icon with explanation
- AND the handler is forced to either:
  - Proceed without redaction, OR
  - Cancel and create a Weigering (denial) for that data

### Scenario: Redaction audit trail

- GIVEN redactions are finalized on the export bundle
- WHEN the bundle is exported as JSON
- THEN the JSON MUST include a separate `"redacties"` array at the root level:
  ```json
  {
    "redacties": [
      {
        "bewijsItemId": "...",
        "veldpad": "$.handhaver.naam",
        "voorWaarde": "J.C. de Boer",
        "naWaarde": "[redacted...]",
        "grond": "bescherming-rechten-derden",
        "uitgevoerdDoor": "s.jansen@gemeente-zeist.nl",
        "uitgevoerdOp": "2026-04-20T10:12:00+02:00"
      }
    ]
  }
  ```
- AND this metadata MUST be signed as part of the PAdES-LTV bundle
- AND the citizen and AP may verify the audit trail using the signature

---

## REQ-AVG-007: Denial with GDPR Article 23 Grounds [MVP]

If the handler cannot fulfill all or part of the request, they MUST create an explicit, justified denial.

### Scenario: Complete denial under Art. 23

- GIVEN a request relates to an ongoing criminal investigation (Art. 23 lid 1 sub d GDPR)
- WHEN the handler clicks "Weiger verzoek" (deny request)
- THEN a form MUST appear with:
  - Radio buttons: "Geheel geweigerd" (complete) vs. "Gedeeltelijk geweigerd" (partial)
  - Dropdown: GDPR Art. 23 exception grounds (Art. 23(1)(a-f) + Art. 23(3) exceptions)
  - Text field: "Motivation (min. 100 characters):"
- AND on selecting "Art. 23 lid 1 sub d" (criminal investigation), the handler MUST enter:
  `"This request would interfere with ongoing investigation reference {id}. Data cannot be disclosed until investigation concludes (estimated {date} or sooner)."`
- AND on save, a Weigering record MUST be created with:
  - `verzoekId: "{request_id}"`
  - `weigering: "geheel"`
  - `grond: "art-23-lid-1-sub-d"`
  - `toelichtingAvg23: "{motivation}"`
  - `ondertekendDoor: "j.de.vries@gemeente-zeist.nl"`
  - `ondertekendOp: "{timestamp}"`

### Scenario: Partial denial with per-scope reasoning

- GIVEN scope `["parkeervergunningen", "facturatie", "communicatie"]`
- WHEN the handler reviews evidence:
  - Parkeervergunningen: No legal grounds for denial ✓
  - Facturatie: Subject to 7-year tax retention (financial records) — erasure impossible
  - Communicatie: No grounds for denial ✓
- THEN the handler MUST be able to select:
  - `weigering: "gedeeltelijk"`
  - `geweigerdeOnderdelen: ["scope:facturatie"]`
  - Separate reasoning per scope in a structured form
- AND the export bundle MUST include:
  - Full access to parkeervergunningen
  - Full access to communicatie
  - For facturatie: a separate PDF page explaining: "Refusal of erasure for financial records under GDPR Art. 23(1)(e) — tax retention obligation. Access is provided in the bundle for your reference."

### Scenario: Mandatory AP complaint reference

- GIVEN a Weigering is being drafted
- WHEN the handler prepares the final denial letter
- THEN the system MUST mandate the following text block:
  ```
  U kunt een klacht indienen bij de Autoriteit Persoonsgegevens (AP) of
  verzoeken om heroverweging via: https://autoriteitpersoonsgegevens.nl/nl/
  Contactgegevens: Autoriteit Persoonsgegevens, Bezwaarmeldingen,
  Postbus 93374, 2509 AJ Den Haag, Tel: 020-5881500
  ```
- AND the system MUST block denial letter generation if this text is missing
- AND on export to PDF, the AP contact info MUST be clickable (mailto: / https: links)

### Scenario: Appeal / rethink workflow

- GIVEN a Weigering has been issued and the citizen has appealed
- WHEN the FG re-reviews the grounds
- THEN the FG may mark the original Weigering as `status: "overschreven"` (overruled)
- AND may create a new Weigering with updated grounds, OR convert the request to full access
- AND both Weigeringen MUST be retained in the audit trail with timestamps

---

## REQ-AVG-008: AP Escalation with Complete Dossier Export [MVP]

If a citizen files a complaint with the Data Protection Authority, the organization must be able to export the complete dossier.

### Scenario: AP complaint received → dossier package

- GIVEN the FG receives notification that an AP complaint has been filed for AVG-2026-0034
- WHEN the FG marks the request as `dpiaFlag: "ap-klacht"` in the admin UI (or the system detects it via incoming mail)
- THEN a background job MUST start assembling a complete dossier ZIP:
  ```
  AVG-2026-0034_AP-Klacht_2026-05-15.zip/
  ├── index.json (manifest with structure)
  ├── verzoek.json (original AvgVerzoek record)
  ├── termijn-events/ (all TermijnEvent records)
  ├── bewijs-items/ (all BewijsItem metadata + content hashes)
  ├── redactie-acties/ (all RedactieActie records)
  ├── export-bundle/ (signed PDF + JSON)
  ├── weigeringen/ (all Weigering records if applicable)
  ├── correspondentie/ (email records, decision letters)
  └── audit-trail/ (complete change history)
  ```
- AND the ZIP MUST be signed with the organization's digital signature
- AND a manifest MUST list all documents with timestamps and hashes for integrity verification
- AND the FG may download the ZIP and submit it directly to the AP

### Scenario: AP annual reporting

- GIVEN the AP requests annual statistics from organizations >250 employees
- WHEN the FG initiates "Generate AP Annual Report"
- THEN the system MUST aggregate (anonymized):
  - Total requests received
  - Breakdown by article (art. 15, 16, 17, 18, 20 counts)
  - Average processing time per article
  - Denial percentage and main grounds
  - Deadline breaches (count + %)
  - Extensions granted (count + reasons)
- AND export in CSV format suitable for AP's prescribed template
- AND ensure no citizen data is exposed (only statistics)

---

## REQ-AVG-009: 5-Year Dossier Retention with Evidence Pseudonymization [MVP]

The organization must retain evidence of correct handling for 5 years (per RvIG guidelines), while pseudonymizing personal data in evidence after 30 days.

### Scenario: Dossier retention until 5-year deadline

- GIVEN an AvgVerzoek is resolved on 2026-04-22T16:45:00+02:00
- WHEN the retention policy is applied
- THEN `retentieTot: 2031-04-22T00:00:00+02:00` MUST be set (5 years later)
- AND the dossier MUST be moved to an "Archief" (archive) tab in the handler interface
- AND it MUST NOT appear in active worklists or dashboard
- AND all CRUD operations MUST be locked (read-only after resolution)
- AND a comment MUST be allowed for post-resolution notes
- AND the complete audit trail MUST remain immutable and queryable

### Scenario: Evidence pseudonymization 30 days after export

- GIVEN an ExportBundle is exported on 2026-04-22
- WHEN the pseudonymization job runs on 2026-05-22 (30 days later)
- THEN for each BewijsItem in `bevatItems: [...]`:
  - The `inhoudPreview` field MUST be anonymized (personal identifiers replaced with placeholders)
  - Metadata fields (`bronApp`, `bronRegister`, `categorie`, `rechtsgrond`, `opgenomenInExport`) MUST remain intact
  - Timestamps and audit information MUST remain intact
  - The original content MUST be cryptographically hashed for integrity verification
- AND example: `"Aanvraag bewonersparkeren zone 7, ingediend door M.W. van der Berg op 12-09-2024"` becomes `"[pseudonymized] requested resident permit zone 7 on [date]"`
- AND the dossier's **AvgVerzoek record itself** (request metadata, handler notes, timeline) MUST NOT be pseudonymized — only the evidence content

### Scenario: Dossier cannot be deleted before 5 years

- GIVEN a handler or even an FG attempts to manually delete a request within the 5-year window
- WHEN the delete action is triggered
- THEN the system MUST refuse: "Retention period active until 2031-04-22. Deletion requires FG approval with audit documentation per RvIG richtlijn."
- AND only an FG with explicit audit justification may trigger early deletion
- AND the audit trail MUST log the deletion and justification permanently

### Scenario: Automatic deletion after 5-year deadline

- GIVEN retention deadline 2031-04-22 has passed and current date is 2031-04-23
- WHEN the cleanup job runs
- THEN the entire AvgVerzoek + all related entities (TermijnEvents, BewijsItems, ExportBundles, Weigeringen, RedactieActies) MUST be deleted
- AND a final audit record MUST be created: `{ "type": "retentie-verloopt-verwijderd", "verzoekId": "...", "deletedAt": "2031-04-23T02:15:00+02:00" }`
- AND the deletion MUST be logged to the central SIEM for compliance records
- AND the citizen MUST NOT be notified (this is automatic cleanup of archived data)

---

## REQ-AVG-010: DPIA Pattern Detection and FG Notification [MVP]

The system MUST detect patterns of similar requests that suggest systemic data processing issues and flag them for DPIA review.

### Scenario: Automatic DPIA flag on 10+ similar requests

- GIVEN 10 art. 17 (erasure) requests arrive within 30 days, all requesting removal of "gegevens marketingdatabase"
- WHEN the weekly DPIA-pattern-detection job runs
- THEN the system MUST:
  1. Group requests by `artikel` + `scope` + word-similarity of `specifiekeVraag`
  2. Count: 10+ in 30-day window = potential systemic issue
  3. Create a DPIA-flag entry
  4. Set `dpiaFlag: true` on all 10 requests
- AND send FG notification: "10 erasure requests detected for marketing data scope in past 30 days. Possible systemic issue. DPIA review recommended."
- AND mark the 10 requests with a purple "⚠ DPIA" badge in the dashboard

### Scenario: Handler manually marks request for DPIA review

- GIVEN a single request that suggests an unusual pattern (e.g., very sensitive scope, multi-part request, odd timing)
- WHEN the handler clicks checkbox "⚠ DPIA-aandacht nodig" (DPIA attention needed)
- THEN `dpiaFlag: true` MUST be set on that request
- AND the FG MUST receive: "Handler flagged AVG-2026-0087 for DPIA review. Reason: [handler notes]. Review recommended."
- AND the FG may link this to other related requests or initiate a formal DPIA

### Scenario: DPIA flag creates Procest improvement task

- GIVEN a DPIA-flag has been set (either automatic or manual)
- WHEN the FG reviews the flagged requests
- AND decides a DPIA is warranted
- THEN clicking "Create Procest Improvement" MUST:
  1. Create a new Procest item with `type: "verbetering"`, `categorie: "gegevensbescherming"`
  2. Link it to the originating AvgVerzoeken
  3. Pre-populate fields: title, description from the pattern analysis
  4. Set assignee to FG or data processing officer
- AND subsequent improvements/remediation MUST be linked back to the original requests
- AND the request cards MUST show: "⊙ DPIA improvement track: PROC-2026-0542"

### Scenario: Monthly DPIA summary for FG

- GIVEN it is the first of each month
- WHEN the FG opens the dashboard
- THEN a "DPIA Summary" card MUST appear showing:
  - Requests flagged this month (count)
  - Top patterns by article + scope
  - Linked Procest items status
  - Recommended actions
- AND the FG may export this as a report for their records

---

## REQ-AVG-011: BSN Validation at Intake [DEPENDENCY]

This requirement depends on the BSN Validation capability (sister feature). At intake, the citizen's BSN must be verified through the BRP lookup before the AvgVerzoek is created.

### Scenario: Valid BSN → BRP lookup succeeds

- GIVEN a citizen submits the web form with BSN "123456782" and name "M.W. van der Berg"
- WHEN the form is submitted
- THEN the system MUST call the BSN validation capability with binding "handling AVG request art. {X}"
- AND the BRP lookup MUST return a match (name, DOB, address confirmed)
- AND `verzoekerBsnGeverifieerd: true` MUST be set on the AvgVerzoek
- AND the request MUST proceed to intake processing

### Scenario: Invalid or unmatched BSN → rejection

- GIVEN a citizen enters BSN "000000000" (invalid)
- WHEN the form is submitted
- THEN the system MUST display: "BSN niet geldig of niet herkenbaar. Controleer uw gegevens of neem contact op."
- AND the AvgVerzoek MUST NOT be created
- AND the citizen MUST be offered an alternative: manual request with additional identity verification

---

## REQ-AVG-012: OpenConnector Integration for External Source Queries [INTEGRATION]

This requirement defines the interface that external systems (via OpenConnector) must expose for AVG data export.

### Scenario: OpenConnector source exposes AVG-export-endpoint

- GIVEN an external CRM system is registered in Pipelinq via OpenConnector
- THEN that system MUST expose a REST API endpoint: `GET /avg-export?bsn={bsn}&scope={scope}&artikel={artikel}`
- AND MUST return a JSON response:
  ```json
  {
    "status": "success",
    "items": [
      { "id": "ext-crm-obj-123", "categorie": "klant-profiel", "inhoud": {...}, "rechtsgrond": "klantrelatie" }
    ]
  }
  ```
- AND MUST respond within 10 seconds
- AND the Pipelinq integration MUST handle timeouts gracefully (mark as `bron-onbereikbaar`)
- AND the organization is responsible for implementing this endpoint on the external system

---

## REQ-AVG-013: Email and Notification Templates [CONFIGURATION]

The organization MUST be able to customize email templates for citizen communication.

### Scenario: Receipt confirmation email customizable

- GIVEN the admin opens Settings → AVG Request Templates
- THEN editable fields MUST include:
  - Receipt confirmation email subject
  - Receipt confirmation email body (with placeholders: {kenmerk}, {artikel}, {deadline}, {handler_contact})
  - Extension notification subject + body
  - Denial letter subject + body
- AND placeholder documentation MUST explain which fields are required (especially AP complaint reference)
- AND preview MUST render the email with sample data
- AND changes MUST be versioned so old requests can render their templates consistently (DocuDesk template versioning)

---
