# Design: avg-verzoeken-workflow

## Architecture

### Data Layer

Six new OpenRegister schemas are introduced to model the AVG request workflow:

**AvgVerzoek** (AVG Request)
- Central request entity linked to a Contact (via BSN validation)
- Tracks article type, legal deadline, handler assignment, and lifecycle state
- Stores reference to evidence bundle, denial record, and retention date
- Includes DPIA-flag for pattern detection

**TermijnEvent** (Deadline Event)
- Immutable log of deadline-related events (receipt confirmation sent, escalation triggered, deadline exceeded)
- Links back to AvgVerzoek
- Used for audit trail and breach documentation

**BewijsItem** (Evidence Item)
- Individual piece of evidence found during collection
- References source system (OpenRegister, BRP, OpenConnector, app)
- Tracks collection timestamp, legal basis, and redaction status
- Includes content preview for bundle assembly

**ExportBundle** (Export Bundle)
- Completed data export in JSON + PDF format
- Stores integrity hash (SHA-256), signature type (PAdES-LTV)
- Tracks download link (30-day validity), expiration, secure delivery method
- Immutable once finalized

**Weigering** (Denial/Refusal)
- Records partial or complete denial with explicit GDPR Art. 23 grounds
- Mandates AP complaint reference URL
- Signed by authorized handler
- Supports per-scope denials (e.g., "access granted, erasure denied")

**RedactieActie** (Redaction Action)
- Tracks each field-level redaction applied before export
- Stores before/after values for audit
- Documents reason (third-party protection, legal obligation, etc.)
- Links to BewijsItem and ExportBundle

All six entities inherit OpenRegister built-in fields: `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

### Frontend

**View Layer:**
- **AVG Requests Dashboard** (`/pipelinq/avg/dashboard`): Lists in-progress and overdue requests with color-coded deadline urgency
  - Handler sees only their own requests or assigned queue
  - Team lead sees all requests in team, with workload distribution
  - FG sees all requests read-only with filter by DPIA-flag

- **AVG Request Detail** (`/pipelinq/avg/requests/:id`): Full lifecycle UI with tabs
  - Intake & Classification
  - Evidence Collection (BewijsItem list with source badges)
  - Redaction (side-by-side editor for before/after)
  - Bundle Preview (PDF viewer + JSON export)
  - Denial (if applicable) with Art. 23 grounds editor

**Components:**
- `AvgIntakeForm.vue`: Web form and manual registration with article selector
- `DeadlineCounter.vue`: Visual timer with urgency coloring (green → yellow → red)
- `EvidenceCollector.vue`: Progress tracker for async evidence collection from federated sources
- `RedactionEditor.vue`: Field-level masking tool with content preview
- `BundlePreview.vue`: PDF viewer + JSON download link
- `DenialForm.vue`: GDPR Art. 23 grounds selector with mandatory AP reference

**Integrations:**
- Nextcloud Notifications API for deadline alerts
- Nextcloud Mail API for citizen communication
- Berichtenbox (Message Box) API for secure bundle delivery

### Backend

**Request Handlers:**
- `AvgRequestController.php`: CRUD for AvgVerzoek with intake form submission
- `EvidenceCollectionController.php`: Trigger async collection job, fetch status
- `BundleGenerationController.php`: Assemble JSON + call DocuDesk for PDF, sign with PKIoverheid cert
- `RedactionController.php`: Apply/preview redactions per BewijsItem
- `DenialController.php`: Create Weigering record with Art. 23 validation
- `RetentionController.php`: Archive/pseudonymize after deadlines

**Background Jobs:**
- `CollectEvidenceJob`: Query OpenRegister, BRP, OpenConnector sources; timeout handling
- `DeadlineTrackerJob`: Hourly check for escalations, 7-day reminder, deadline breach
- `ExtensionWindowCheckerJob`: Daily check that extension is communicated by day 30
- `DpiaPatternDetectionJob`: Weekly analysis for 10+ requests with same article+scope
- `PseudonymizationJob`: 30 days after export, mask evidence content
- `RetentionCleanupJob`: 5 years after resolution, delete AvgVerzoek + children

**Database Schema Additions:**
- `avg_verzoeken` table with indexed columns: `artikel`, `wettelijke_termijn_verloopt`, `status`, `behandelaar`, `dpia_flag`
- `termijn_events` table with indexed column: `verzoek_id`, `type`
- `bewijs_items` table with indexed columns: `verzoek_id`, `bron_app`, `ingesloten_in_export`
- `export_bundles` table with indexed columns: `verzoek_id`, `ondertekend`, `download_verloopt`
- `weigeringen` table with indexed column: `verzoek_id`
- `redactie_acties` table with indexed columns: `bundle_id`, `bewijs_item_id`

**API Endpoints (REST):**
- `POST /api/v2/avg-verzoeken` — Create intake submission
- `GET /api/v2/avg-verzoeken?status=...&artikel=...` — List with filtering
- `GET /api/v2/avg-verzoeken/{id}` — Fetch full request with all related entities
- `PATCH /api/v2/avg-verzoeken/{id}` — Update handler, status, or notes
- `POST /api/v2/avg-verzoeken/{id}/collect-evidence` — Start async collection
- `GET /api/v2/avg-verzoeken/{id}/evidence-status` — Check collection progress
- `POST /api/v2/avg-verzoeken/{id}/generate-bundle` — Assemble and sign export
- `POST /api/v2/avg-verzoeken/{id}/redact` — Apply field-level redactions
- `POST /api/v2/avg-verzoeken/{id}/deny` — Create Weigering with Art. 23 grounds
- `POST /api/v2/avg-verzoeken/{id}/extend` — Request 60-day extension with reasoning
- `GET /api/v2/export-bundles/{bundleId}/download` — Secure download with one-time link
- `POST /api/v2/avg-verzoeken/{id}/ap-escalate` — Export complete dossier ZIP

**Configuration:**
- `avg_request_settings` in admin settings:
  - Email template for receipt confirmation
  - Email template for deadline extension notification
  - PKIoverheid certificate path for PAdES-LTV signing
  - DPIA-threshold (default: 10 requests in 30 days)
  - Evidence retention days (default: 30)
  - Dossier retention years (default: 5)
  - OpenConnector source mappings per organization

### Integration Points

**OpenRegister**:
- AvgVerzoek, TermijnEvent, BewijsItem, ExportBundle, Weigering, RedactieActie are first-class OpenRegister schemas
- Leverages OpenRegister CRUD API, full-text search, filtering, pagination, file attachments, audit trails
- BewijsItem store can grow very large; use OpenRegister pseudonymization capability after 30 days to mask PII while retaining metadata

**Pipelinq Client Management**:
- Contact entity is enriched with `lopendeAvgVerzoeken: [...]` array
- Handler can see all open AVG requests linked to a specific contact
- On art. 17 completion (erasure), contact may be marked "requested-erasure" or fully pseudonymized per org policy

**Pipelinq Request Management**:
- AvgVerzoek inherits generic Request state machine (submitted → in-treatment → resolved) plus AVG-specific states:
  - `bewijs-verzamelen` (evidence collection in progress)
  - `redactie` (awaiting redaction review)
  - `bundle-genereren` (generating PDF)
  - `wachten-op-verzoeker` (waiting for citizen decision on denial)
  - `weigering-opgesteld` (denial drafted, pending FG sign-off)

**BSN Validation Capability**:
- At intake, automatic BRP lookup with binding "handling AVG request art. {X}"
- BSN verification is mandatory before proceeding; citizen identity confirmed

**OpenConnector**:
- Each integrated source (external CRM, third-party system) must expose an "AVG-export-endpoint"
- Pipelinq queries: `GET /avg-export?bsn={citizen_bsn}&scope={requested_scope}`
- Timeout: 10 seconds per source; timeouts trigger BewijsItem with `categorie: "bron-onbereikbaar"`
- Handler notified of incomplete collection for manual supplementation

**DocuDesk**:
- PDF rendering with org branding, table of contents per category, per-item legal basis header
- PAdES-LTV signing with municipality PKIoverheid certificate
- Template versioning so old requests remain reproducible

**Procest (Process Improvement)**:
- DPIA-flags automatically create Procest improvement item
- Improvements linked back to originating AvgVerzoeken for traceability

**Nextcloud Notifications & Mail**:
- Deadline reminders to handler (7 days before, daily at 3 days, escalation at <72 hours)
- Extension notification to citizen with reasoning
- Denial letter with AP complaint reference
- Secure download link via email or Berichtenbox (not as attachment)

**SIEM / Audit Logging**:
- All TermijnEvents exported via webhook to central security logging
- Denial actions logged for compliance audits
- Redaction actions with before/after values for accountability

## Components

See `specs/` directory for detailed requirement specifications with BDD scenarios.

## i18n

New translation keys follow ADR-007 sentence case. Keys are added to `l10n/en.json` and `l10n/nl.json`:

| Key | English | Dutch |
|-----|---------|-------|
| `AVG Request - Article {article}` | `AVG Request - Article {article}` | `AVG Verzoek - Artikel {article}` |
| `Legal deadline` | `Legal deadline` | `Wettelijke termijn` |
| `Days remaining` | `Days remaining` | `Dagen resterend` |
| `Extension with justification` | `Extension with justification` | `Verlenging met onderbouwing` |
| `Denied under GDPR Art. 23` | `Denied under GDPR Art. 23` | `Geweigerd onder AVG Art. 23` |
| `Redact for third-party protection` | `Redact for third-party protection` | `Redigeren ter bescherming derden` |
| `Evidence collection in progress` | `Evidence collection in progress` | `Bewijsverzameling in behandeling` |
| `PDF and JSON export ready` | `PDF and JSON export ready` | `PDF en JSON export klaar` |

## Files Changed

### New Files

| File | Purpose |
|------|---------|
| `src/schemas/AvgVerzoek.ts` | OpenRegister schema definition |
| `src/schemas/TermijnEvent.ts` | OpenRegister schema definition |
| `src/schemas/BewijsItem.ts` | OpenRegister schema definition |
| `src/schemas/ExportBundle.ts` | OpenRegister schema definition |
| `src/schemas/Weigering.ts` | OpenRegister schema definition |
| `src/schemas/RedactieActie.ts` | OpenRegister schema definition |
| `src/views/AvgDashboard.vue` | Handler dashboard with deadline tracking |
| `src/views/AvgRequestDetail.vue` | Full request lifecycle UI |
| `src/components/AvgIntakeForm.vue` | Intake form component |
| `src/components/DeadlineCounter.vue` | Visual deadline timer |
| `src/components/EvidenceCollector.vue` | Evidence collection progress |
| `src/components/RedactionEditor.vue` | Redaction tool |
| `src/components/BundlePreview.vue` | PDF + JSON preview |
| `src/components/DenialForm.vue` | GDPR Art. 23 denial form |
| `lib/Controller/AvgRequestController.php` | Request CRUD |
| `lib/Controller/EvidenceCollectionController.php` | Evidence collection |
| `lib/Controller/BundleGenerationController.php` | Bundle assembly |
| `lib/Controller/RedactionController.php` | Redaction operations |
| `lib/Controller/DenialController.php` | Denial creation |
| `lib/Controller/RetentionController.php` | Retention policy |
| `lib/Service/EvidenceCollectionService.php` | Federated source queries |
| `lib/Service/BundleService.php` | Bundle generation + signing |
| `lib/Service/RedactionService.php` | Redaction logic |
| `lib/Service/DpiaDetectionService.php` | Pattern detection |
| `lib/Job/CollectEvidenceJob.php` | Background job |
| `lib/Job/DeadlineTrackerJob.php` | Deadline monitoring |
| `lib/Job/DpiaPatternDetectionJob.php` | Weekly pattern check |
| `lib/Job/PseudonymizationJob.php` | 30-day evidence masking |
| `lib/Job/RetentionCleanupJob.php` | 5-year deletion |

### Modified Files

| File | Change |
|------|--------|
| `l10n/en.json` | Add AVG request translation keys |
| `l10n/nl.json` | Add Dutch AVG request translation keys |
| `src/schemas/Contact.ts` | Add `lopendeAvgVerzoeken: []` field |
| `lib/Db/ContactMapper.php` | Eager-load linked AvgVerzoeken |
| `src/schemas/Request.ts` | Document AVG-specific state machine states |

## Seed Data

Example Dutch seed data for AVG requests, demonstrating typical citizen scenarios:

**AvgVerzoek examples:**
```json
{
  "kenmerk": "AVG-2026-0001",
  "verzoekerNaam": "Dhr. M.J. Hendriks",
  "verzoekerBsn": "123456789",
  "artikel": "art-15-inzage",
  "scope": ["parkeervergunningen", "communicatie"],
  "status": "in-behandeling",
  "behandelaar": "medewerker:j.smit@gemeente.nl",
  "wettelijkeTermijnVerloopt": "2026-05-22T23:59:59+02:00"
}
```

**TermijnEvent examples:**
```json
{
  "type": "ontvangstbevestiging-verstuurd",
  "deadline": "2026-04-08T15:14:32+02:00",
  "automatisch": true,
  "geslaagd": true
}
```

**BewijsItem examples:**
```json
{
  "bronApp": "openregister",
  "bronRegister": "parkeervergunningen",
  "categorie": "vergunningsaanvraag",
  "rechtsgrond": "wettelijke taak — Wegenverkeerswet",
  "opgenomenInExport": true
}
```

**ExportBundle example:**
```json
{
  "bevatItems": 47,
  "formaat": ["json", "pdf"],
  "ondertekend": true,
  "ondertekeningsType": "PAdES-LTV"
}
```

**Weigering example:**
```json
{
  "weigering": "gedeeltelijk",
  "geweigerdeOnderdelen": ["scope:facturatie"],
  "grond": "art-23-lid-1-sub-e",
  "toelichtingAvg23": "Financial records fall under tax retention obligation (7 years). Access is provided in the bundle; erasure is not possible."
}
```

**RedactieActie example:**
```json
{
  "veldpad": "$.handhaver.naam",
  "voorWaarde": "J.C. de Boer",
  "naWaarde": "[redacted: third-party employee — GDPR Art. 41]",
  "grond": "bescherming-rechten-derden"
}
```
