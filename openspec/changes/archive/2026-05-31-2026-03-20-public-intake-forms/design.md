# Design: public-intake-forms

## Architecture

### Data Model

Two new schemas added to `lib/Settings/pipelinq_register.json`:

#### intakeForm

Defines a customizable web form that can be embedded on external websites. Submissions create contacts and leads in Pipelinq.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Form name |
| fields | array | No | Ordered list of form field definitions |
| fieldMappings | object | No | Maps form field names to contact/lead properties |
| targetPipeline | string (uuid) | No | Pipeline where new leads are placed |
| targetStage | string | No | Initial stage name for new leads |
| notifyUser | string | No | Nextcloud user to notify on submission |
| isActive | boolean | No | Whether the form accepts submissions |
| submitCount | integer | No | Total submissions received |
| successMessage | string | No | Message shown after successful submission |

Each entry in `fields` is an object with:
- `name` (string) — machine-readable field key
- `type` (string, enum: text/textarea/email/phone/select/checkbox/file/hidden) — input type
- `label` (string) — human-readable label
- `required` (boolean) — whether the field is mandatory
- `placeholder` (string, optional) — placeholder text
- `options` (array of string, optional) — choices for select fields

#### intakeSubmission

Records each form submission with submitted data, created entities, and processing status.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| form | string (uuid) | Yes | UUID reference to the intake form |
| submittedAt | string (datetime) | Yes | When the submission was received |
| data | object | No | Submitted form data (key-value pairs) |
| contactId | string (uuid) | No | UUID of created or matched contact |
| leadId | string (uuid) | No | UUID of created lead |
| ip | string | No | Submitter IP address (for rate limiting audit) |
| status | string (enum: processed/rejected/spam) | Yes | Processing status |

### Backend

#### IntakeFormService (`lib/Service/IntakeFormService.php`)

All business logic for form processing. Stateless service with constructor-injected dependencies.

| Method | Description |
|--------|-------------|
| `processSubmission(array $formData, string $formId, string $ip): array` | Validate submission, check honeypot, check rate limit, deduplicate contact by email, create contact + lead, record submission, notify user |
| `checkRateLimit(string $ip, string $formId): bool` | Check APCu for submission count from IP within 5 minutes. Returns false if limit exceeded (max 5 per IP per form per 5 min) |
| `deduplicateContact(string $email): ?array` | Search existing contacts by email via OpenRegister. Returns matched contact or null |
| `generateEmbedCode(string $formId, string $baseUrl): array` | Generate iframe and JS embed snippets. Returns `['iframe' => '...', 'js' => '...']` |
| `exportSubmissionsCsv(string $formId): string` | Generate CSV string of all submissions for the given form |

**Honeypot field**: The public form definition includes a hidden `_hp` field. `processSubmission()` rejects any submission where `_hp` is non-empty, recording status `spam`.

**Rate limiting**: APCu key `pipelinq_ratelimit_{ip}_{formId}` stores a counter. If counter ≥ 5 within 300 seconds, submission is rejected with status `rejected`.

**Contact deduplication**: Before creating a new contact, `deduplicateContact()` queries OpenRegister for contacts where `email = {submitted email}`. If a match is found, the existing contact UUID is used; otherwise a new `contact` object is created.

#### PublicFormController (`lib/Controller/PublicFormController.php`)

Public (no-auth) endpoints. Annotated with `#[PublicPage]` and `#[NoCSRFRequired]`. CORS headers added for cross-origin embedding.

| Method | URL | Action |
|--------|-----|--------|
| GET | `/index.php/apps/pipelinq/api/public/forms/{id}` | Get form definition (fields only, no internal config) |
| POST | `/index.php/apps/pipelinq/api/public/forms/{id}/submit` | Submit form data |
| OPTIONS | `/index.php/apps/pipelinq/api/public/forms/{id}/submit` | CORS preflight |

The GET endpoint returns only fields, successMessage, and name — never targetPipeline, notifyUser, or fieldMappings (internal).

#### IntakeFormController (`lib/Controller/IntakeFormController.php`)

Authenticated management endpoints. Uses standard `#[NoAdminRequired]`.

| Method | URL | Action |
|--------|-----|--------|
| GET | `/index.php/apps/pipelinq/api/forms/{id}/embed` | Get embed code (iframe + JS) |
| GET | `/index.php/apps/pipelinq/api/forms/{id}/submissions` | List submissions (paginated) |
| GET | `/index.php/apps/pipelinq/api/forms/{id}/submissions/export` | Download CSV |

Form CRUD (create, read, update, delete, list) is handled by OpenRegister's generic object API — no custom CRUD endpoints needed.

### Frontend

#### FormManager.vue (`src/views/forms/FormManager.vue`)

Uses `CnIndexPage` with `useListView('intakeForm', ...)`. Columns: name, isActive (badge), submitCount, actions.

Row actions: Edit (navigate to FormBuilder), Embed Code (opens CnCopyDialog with iframe/JS snippets), Submissions (navigate to FormSubmissions), Deactivate/Activate toggle.

Add button opens FormBuilder at `/forms/new`.

#### FormBuilder.vue (`src/views/forms/FormBuilder.vue`)

Full-page form editor. Two modes: create (`id === 'new'`) and edit.

Sections:
- **General**: name (required), successMessage, isActive toggle
- **Target**: targetPipeline (select from pipeline list), targetStage (string)
- **Notification**: notifyUser (Nextcloud user picker)
- **Fields**: ordered list of field definitions. Each row: type selector, label input, required toggle, placeholder input, options input (comma-separated, visible only for `select` type). Move up/down buttons.
- **Field mappings**: two-column table mapping field `name` to a contact/lead property (e.g., `email → contact.email`)

Save via `objectStore.saveObject('intakeForm', data)`.

#### FormSubmissions.vue (`src/views/forms/FormSubmissions.vue`)

Uses `CnDataTable` with columns: submittedAt, status (CnStatusBadge), contactId (link), leadId (link), ip.

Header actions: Export CSV button (calls `/api/forms/{id}/submissions/export`, triggers browser download).

## Files Changed

| File | Action | Description |
|------|--------|-------------|
| `lib/Settings/pipelinq_register.json` | MODIFY | Add `intakeForm` and `intakeSubmission` schemas; add to register schemas array |
| `lib/Service/IntakeFormService.php` | CREATE | Submission processing, rate limiting, contact dedup, embed code, CSV export |
| `lib/Controller/PublicFormController.php` | CREATE | Public form rendering and submission endpoints (`#[PublicPage]`) |
| `lib/Controller/IntakeFormController.php` | CREATE | Embed code, submissions list, CSV export endpoints |
| `appinfo/routes.php` | MODIFY | Add public form routes (before wildcard) and management routes |
| `src/store/store.js` | MODIFY | Register `intakeForm` and `intakeSubmission` object types |
| `src/router/index.js` | MODIFY | Add `/forms`, `/forms/:id`, `/forms/:id/submissions` routes |
| `src/navigation/MainMenu.vue` | MODIFY | Add "Formulieren" nav item with form icon |
| `src/views/forms/FormManager.vue` | CREATE | Form list view |
| `src/views/forms/FormBuilder.vue` | CREATE | Form builder with field editor and configuration |
| `src/views/forms/FormSubmissions.vue` | CREATE | Submission history table with CSV export |

## Seed Data

### intakeForm — 4 example objects (Dutch)

```json
[
  {
    "name": "Contactformulier",
    "fields": [
      {"name": "naam", "type": "text", "label": "Naam", "required": true, "placeholder": "Uw volledige naam"},
      {"name": "email", "type": "email", "label": "E-mailadres", "required": true, "placeholder": "uw@email.nl"},
      {"name": "telefoon", "type": "phone", "label": "Telefoonnummer", "required": false, "placeholder": "+31 6 12345678"},
      {"name": "bericht", "type": "textarea", "label": "Bericht", "required": true, "placeholder": "Uw bericht..."}
    ],
    "fieldMappings": {"naam": "contact.name", "email": "contact.email", "telefoon": "contact.phone"},
    "targetPipeline": "00000000-0000-0000-0000-000000000001",
    "targetStage": "Nieuw",
    "notifyUser": "verkoop",
    "isActive": true,
    "submitCount": 42,
    "successMessage": "Bedankt voor uw bericht! We nemen zo spoedig mogelijk contact met u op."
  },
  {
    "name": "Offerte aanvragen",
    "fields": [
      {"name": "bedrijfsnaam", "type": "text", "label": "Bedrijfsnaam", "required": true, "placeholder": "Uw bedrijfsnaam"},
      {"name": "email", "type": "email", "label": "E-mailadres", "required": true, "placeholder": "info@bedrijf.nl"},
      {"name": "dienst", "type": "select", "label": "Gewenste dienst", "required": true, "options": ["Advies", "Implementatie", "Support", "Training"]},
      {"name": "omschrijving", "type": "textarea", "label": "Projectomschrijving", "required": false, "placeholder": "Beschrijf uw project..."},
      {"name": "budget", "type": "select", "label": "Budgetindicatie", "required": false, "options": ["< €5.000", "€5.000 - €25.000", "€25.000 - €100.000", "> €100.000"]}
    ],
    "fieldMappings": {"bedrijfsnaam": "contact.name", "email": "contact.email", "dienst": "lead.title"},
    "targetPipeline": "00000000-0000-0000-0000-000000000001",
    "targetStage": "Gekwalificeerd",
    "notifyUser": "sales_manager",
    "isActive": true,
    "submitCount": 17,
    "successMessage": "Uw offerteaanvraag is ontvangen. Wij sturen u binnen 2 werkdagen een voorstel toe."
  },
  {
    "name": "Nieuwsbrief inschrijving",
    "fields": [
      {"name": "naam", "type": "text", "label": "Naam", "required": true, "placeholder": "Uw naam"},
      {"name": "email", "type": "email", "label": "E-mailadres", "required": true, "placeholder": "uw@email.nl"},
      {"name": "interesse", "type": "select", "label": "Interessegebied", "required": false, "options": ["Nieuws", "Productupdates", "Events", "Alle berichten"]}
    ],
    "fieldMappings": {"naam": "contact.name", "email": "contact.email"},
    "targetPipeline": "00000000-0000-0000-0000-000000000002",
    "targetStage": "Geïnteresseerd",
    "notifyUser": "marketing",
    "isActive": true,
    "submitCount": 128,
    "successMessage": "U bent succesvol ingeschreven voor onze nieuwsbrief!"
  },
  {
    "name": "Demo aanvragen (inactief)",
    "fields": [
      {"name": "naam", "type": "text", "label": "Naam", "required": true, "placeholder": "Uw naam"},
      {"name": "email", "type": "email", "label": "E-mailadres", "required": true, "placeholder": "uw@email.nl"},
      {"name": "organisatie", "type": "text", "label": "Organisatie", "required": false, "placeholder": "Naam van uw organisatie"},
      {"name": "voorkeursdatum", "type": "text", "label": "Voorkeursdatum", "required": false, "placeholder": "bijv. volgende week dinsdag"}
    ],
    "fieldMappings": {"naam": "contact.name", "email": "contact.email", "organisatie": "contact.role"},
    "targetPipeline": "00000000-0000-0000-0000-000000000001",
    "targetStage": "Demo",
    "notifyUser": "demo_team",
    "isActive": false,
    "submitCount": 5,
    "successMessage": "Uw demoverzoek is ontvangen. Wij plannen de demo in en sturen u een uitnodiging."
  }
]
```

### intakeSubmission — 4 example objects (Dutch)

```json
[
  {
    "form": "00000000-0000-0000-0000-000000000010",
    "submittedAt": "2026-03-18T09:14:32Z",
    "data": {
      "naam": "Fatima El-Amrani",
      "email": "fatima@voorbeeld.nl",
      "telefoon": "+31 6 23456789",
      "bericht": "Ik wil graag meer informatie over uw dienstverlening."
    },
    "contactId": "00000000-0000-0000-0000-000000000020",
    "leadId": "00000000-0000-0000-0000-000000000030",
    "ip": "83.84.100.12",
    "status": "processed"
  },
  {
    "form": "00000000-0000-0000-0000-000000000011",
    "submittedAt": "2026-03-19T14:02:11Z",
    "data": {
      "bedrijfsnaam": "Bakker & Zonen B.V.",
      "email": "info@bakker-zonen.nl",
      "dienst": "Implementatie",
      "omschrijving": "Wij zoeken ondersteuning bij de implementatie van een nieuw CRM-systeem.",
      "budget": "€25.000 - €100.000"
    },
    "contactId": "00000000-0000-0000-0000-000000000021",
    "leadId": "00000000-0000-0000-0000-000000000031",
    "ip": "62.45.200.3",
    "status": "processed"
  },
  {
    "form": "00000000-0000-0000-0000-000000000010",
    "submittedAt": "2026-03-20T08:55:00Z",
    "data": {
      "naam": "",
      "email": "",
      "_hp": "spam@bot.ru",
      "bericht": "Buy cheap meds online!!!"
    },
    "contactId": null,
    "leadId": null,
    "ip": "185.220.101.47",
    "status": "spam"
  },
  {
    "form": "00000000-0000-0000-0000-000000000010",
    "submittedAt": "2026-03-20T11:30:45Z",
    "data": {
      "naam": "Jan-Willem van der Berg",
      "email": "jwvdberg@mkbbedrijf.nl",
      "telefoon": "+31 35 5678901",
      "bericht": "Kunnen jullie ons helpen met de digitalisering van onze klantprocessen?"
    },
    "contactId": "00000000-0000-0000-0000-000000000022",
    "leadId": "00000000-0000-0000-0000-000000000032",
    "ip": "145.220.50.18",
    "status": "processed"
  }
]
```
