# Public Intake Forms — Delta Spec

## Purpose

Define requirements for the web-to-lead intake forms feature: form lifecycle management, public rendering and submission, contact deduplication, spam protection, rate limiting, embed code generation, and submission history.

**Main spec ref**: [public-intake-forms/spec.md](../../../../specs/public-intake-forms/spec.md)
**Feature tier**: Core

---

## Requirements

### REQ-IF-001: Form CRUD

The system MUST provide full create, read, update, and delete lifecycle management for intake forms.

#### Scenario: Create a new intake form

- GIVEN an authenticated user navigates to the Forms section
- WHEN they click "Nieuw formulier" and fill in name "Contactformulier" with at least one field
- THEN an `intakeForm` object MUST be created in OpenRegister via `objectStore.saveObject`
- AND the form MUST appear in the FormManager list
- AND `isActive` MUST default to `false`

#### Scenario: Edit an existing intake form

- GIVEN an existing form "Contactformulier"
- WHEN the user edits the name to "Contactformulier v2" and saves
- THEN the form MUST be updated via `objectStore.saveObject` with the same UUID
- AND the FormManager list MUST reflect the new name

#### Scenario: Name is required on create

- GIVEN the user opens the FormBuilder with an empty name field
- WHEN they attempt to save
- THEN the save MUST be prevented
- AND a validation error MUST appear: "Naam is verplicht"

#### Scenario: Activate and deactivate a form

- GIVEN a form "Contactformulier" with `isActive: false`
- WHEN the user clicks "Activeren" in the FormManager
- THEN `isActive` MUST be set to `true` and saved
- AND the status badge MUST change to "Actief"
- AND submissions MUST now be accepted

- GIVEN a form with `isActive: true`
- WHEN the user clicks "Deactiveren"
- THEN `isActive` MUST be set to `false`
- AND new submissions to this form MUST return HTTP 403

#### Scenario: Delete an intake form

- GIVEN an existing form "Oud formulier"
- WHEN the user clicks delete and confirms the dialog
- THEN the form MUST be deleted via `objectStore.deleteObject`
- AND the form MUST no longer appear in the FormManager list
- AND its existing submissions MUST be retained in OpenRegister

---

### REQ-IF-002: Form Field Configuration

The form builder MUST support configuring ordered field lists with type, label, required flag, placeholder, and options.

#### Scenario: Add a text field

- GIVEN the user is in FormBuilder
- WHEN they add a field with type "text", label "Naam", required: true
- THEN the field MUST appear in the `fields` array with `{"name": "naam", "type": "text", "label": "Naam", "required": true}`
- AND the field MUST render as `<input type="text" required>` in the public form

#### Scenario: Add a select field with options

- GIVEN the user adds a field with type "select" and label "Dienst"
- WHEN they enter options "Advies, Implementatie, Support"
- THEN the field options array MUST contain `["Advies", "Implementatie", "Support"]`
- AND the public form MUST render a `<select>` with those three `<option>` elements

#### Scenario: Reorder fields with move buttons

- GIVEN a form with fields in order: [Naam, Email, Bericht]
- WHEN the user clicks the "Omhoog" button on "Bericht"
- THEN the field order MUST become [Naam, Bericht, Email]
- AND the `fields` array order MUST match the displayed order on save

#### Scenario: Supported field types

- The system MUST support the following field types: text, textarea, email, phone, select, checkbox, file, hidden
- GIVEN a field with type "email"
- WHEN a submitter enters "geen-email"
- THEN the public form MUST reject the submission client-side and show a format error

#### Scenario: Field-to-entity property mapping

- GIVEN a field named "email" in the form builder
- WHEN the user selects mapping "contact.email"
- THEN `fieldMappings["email"]` MUST be set to `"contact.email"`
- AND `IntakeFormService::processSubmission` MUST use this mapping to populate the contact object

---

### REQ-IF-003: Public Form Rendering

The system MUST serve form definitions on a public endpoint requiring no authentication.

#### Scenario: Fetch active form definition

- GIVEN an active form with UUID `{id}`
- WHEN an unauthenticated request is made to `GET /api/public/forms/{id}`
- THEN the response MUST contain: `name`, `fields` (with type/label/required/placeholder/options), `successMessage`
- AND the response MUST NOT contain: `targetPipeline`, `targetStage`, `notifyUser`, `fieldMappings`, `submitCount`
- AND HTTP status MUST be 200

#### Scenario: Inactive form returns 403

- GIVEN a form with `isActive: false`
- WHEN `GET /api/public/forms/{id}` is called
- THEN the response MUST return HTTP 403 with message "Formulier is niet actief"

#### Scenario: Non-existent form returns 404

- GIVEN a UUID that does not match any form
- WHEN `GET /api/public/forms/{id}` is called
- THEN the response MUST return HTTP 404

#### Scenario: CORS headers allow cross-origin embedding

- GIVEN a form embedded on an external website at `https://klant.nl`
- WHEN the browser issues a preflight `OPTIONS /api/public/forms/{id}/submit`
- THEN the response MUST include `Access-Control-Allow-Origin: *`
- AND `Access-Control-Allow-Methods: POST, OPTIONS`

---

### REQ-IF-004: Form Submission — Contact and Lead Creation

Submitting a public form MUST create or match a contact and create a new lead in the configured pipeline.

#### Scenario: Successful submission creates contact and lead

- GIVEN an active form mapped to pipeline "Sales Pipeline" / stage "Nieuw"
- WHEN `POST /api/public/forms/{id}/submit` is called with valid data `{naam: "Fatima El-Amrani", email: "fatima@voorbeeld.nl"}`
- THEN `IntakeFormService::processSubmission` MUST:
  - Create a `contact` object with `name: "Fatima El-Amrani"` and `email: "fatima@voorbeeld.nl"`
  - Create a `lead` object with `source: "intake-form"`, `pipeline: {targetPipeline}`, `stage: "Nieuw"`, `contact: {contactUuid}`
  - Create an `intakeSubmission` object with `status: "processed"`, `contactId`, `leadId`, `ip`
  - Increment `intakeForm.submitCount` by 1
- AND the response MUST return HTTP 200 with `{"status": "ok", "message": "{successMessage}"}`

#### Scenario: Submission with missing required field returns 422

- GIVEN a form with `naam` marked as required
- WHEN the submission body omits the `naam` field
- THEN the response MUST return HTTP 422 with `{"error": "Verplicht veld ontbreekt: naam"}`
- AND no contact, lead, or submission object MUST be created

#### Scenario: Submission notifies configured user

- GIVEN a form with `notifyUser: "verkoop"`
- WHEN a submission is successfully processed
- THEN a Nextcloud notification MUST be sent to user "verkoop" via `IManager::notify`
- AND the notification subject MUST include the form name

---

### REQ-IF-005: Contact Deduplication

The system MUST detect existing contacts by email address to avoid creating duplicates.

#### Scenario: Matching contact is reused

- GIVEN a contact "Jan Jansen" with `email: "jan@voorbeeld.nl"` already exists in OpenRegister
- WHEN a form submission arrives with `email: "jan@voorbeeld.nl"`
- THEN `IntakeFormService::deduplicateContact("jan@voorbeeld.nl")` MUST return the existing contact UUID
- AND NO new contact object MUST be created
- AND the new lead MUST reference the existing contact UUID

#### Scenario: New email creates new contact

- GIVEN no contact with `email: "nieuw@bedrijf.nl"` exists
- WHEN a form submission arrives with that email
- THEN a new `contact` object MUST be created
- AND `intakeSubmission.contactId` MUST reference the new contact UUID

#### Scenario: Submission without email skips deduplication

- GIVEN a form where the email field is not required and not submitted
- WHEN the submission is processed
- THEN deduplication MUST be skipped
- AND a new contact MUST still be created if name is available
- AND `intakeSubmission.contactId` MUST be set if a contact was created

---

### REQ-IF-006: Honeypot Spam Protection

The system MUST reject submissions where the honeypot field is filled in.

#### Scenario: Honeypot filled — submission rejected as spam

- GIVEN the public form definition includes a hidden `_hp` field with `display: none` styling
- WHEN a submission is received with `_hp` set to any non-empty value
- THEN `IntakeFormService::processSubmission` MUST reject the submission
- AND an `intakeSubmission` object MUST be created with `status: "spam"`
- AND NO contact or lead MUST be created
- AND the response MUST return HTTP 200 with the success message (to not reveal detection to bots)

#### Scenario: Honeypot empty — submission proceeds normally

- GIVEN a submission where `_hp` is absent or empty string
- WHEN `processSubmission` is called
- THEN the honeypot check MUST pass
- AND submission processing MUST continue to rate limit check

#### Scenario: Honeypot field not visible to real users

- GIVEN the public form rendered in a browser
- WHEN a real user fills in the form
- THEN the `_hp` field MUST NOT be visible (styled with `display: none` or `visibility: hidden`)
- AND the field MUST NOT be labelled or described to screen readers (`aria-hidden: true`)

---

### REQ-IF-007: Rate Limiting per IP

The system MUST limit submissions per IP address to prevent flooding.

#### Scenario: Under rate limit — submission allowed

- GIVEN an IP address "83.84.100.12" that has submitted 4 times to form `{id}` in the last 5 minutes
- WHEN a 5th submission arrives from that IP
- THEN the submission MUST be allowed
- AND the APCu counter MUST be incremented to 5

#### Scenario: Over rate limit — submission rejected

- GIVEN an IP address that has submitted 5 times to form `{id}` within 5 minutes
- WHEN a 6th submission arrives from that IP
- THEN `IntakeFormService::checkRateLimit` MUST return false
- AND an `intakeSubmission` MUST be created with `status: "rejected"`
- AND the response MUST return HTTP 429 with `{"error": "Te veel aanvragen. Probeer het later opnieuw."}`
- AND NO contact or lead MUST be created

#### Scenario: Rate limit resets after 5 minutes

- GIVEN an IP that was rate-limited
- WHEN 5 minutes have elapsed (APCu TTL expires)
- THEN the next submission from that IP MUST be allowed

---

### REQ-IF-008: Embed Code Generation

The system MUST generate embed code that allows the form to be embedded on external websites.

#### Scenario: Get embed code for a form

- GIVEN an active form with UUID `{id}`
- WHEN an authenticated user calls `GET /api/forms/{id}/embed`
- THEN the response MUST contain:
  ```json
  {
    "iframe": "<iframe src=\"https://{nextcloud}/apps/pipelinq/public/form/{id}\" width=\"100%\" height=\"600\" frameborder=\"0\"></iframe>",
    "js": "<script src=\"https://{nextcloud}/apps/pipelinq/js/form-embed.js\" data-form-id=\"{id}\"></script>"
  }
  ```
- AND both snippets MUST use the server's actual base URL

#### Scenario: Embed code copy dialog in FormManager

- GIVEN the FormManager list showing form "Contactformulier"
- WHEN the user clicks the "Insluitcode" action
- THEN a `CnCopyDialog` MUST open showing both the iframe and JS snippets in separate tabs
- AND each snippet MUST have a copy button that copies the code to clipboard

#### Scenario: Embed code for inactive form still generated

- GIVEN a form with `isActive: false`
- WHEN `GET /api/forms/{id}/embed` is called by an authenticated user
- THEN the embed code MUST still be returned (authentication required, form management action)
- AND the embedded form itself will show an inactive message when loaded

---

### REQ-IF-009: Submission History and CSV Export

The system MUST provide a paginated submission history view and CSV export capability.

#### Scenario: View submission history

- GIVEN a form "Contactformulier" with 42 submissions
- WHEN the user navigates to FormSubmissions for that form
- THEN a paginated table MUST display submissions with columns: submittedAt, status, contactId (link), leadId (link), ip
- AND the table MUST support standard `_page` + `_limit` pagination
- AND status MUST be shown as a `CnStatusBadge` (processed=green, rejected=orange, spam=red)

#### Scenario: List submissions via API

- GIVEN form `{id}` with submissions
- WHEN `GET /api/forms/{id}/submissions` is called
- THEN the response MUST return paginated `intakeSubmission` objects
- AND the response MUST include `total`, `page`, `pages` fields

#### Scenario: Export submissions as CSV

- GIVEN form "Offerte aanvragen" with 17 submissions
- WHEN the user clicks "Exporteren als CSV" in FormSubmissions
- THEN a `GET /api/forms/{id}/submissions/export` request MUST be made
- AND the response MUST have `Content-Type: text/csv`
- AND `Content-Disposition: attachment; filename="submissions-{formId}.csv"`
- AND the CSV MUST include a header row and one data row per submission
- AND each row MUST contain: submittedAt, status, contactId, leadId, ip, and all submitted `data` fields as columns

#### Scenario: Empty submission history

- GIVEN a form with `submitCount: 0`
- WHEN the user views FormSubmissions
- THEN an empty state MUST display: "Nog geen inzendingen ontvangen"
- AND the "Exporteren als CSV" button MUST be hidden

---

### REQ-IF-010: Form Manager UI

The system MUST provide a dedicated Forms section in the Pipelinq navigation with a list view of all intake forms.

#### Scenario: Forms navigation item visible

- GIVEN an authenticated Pipelinq user
- WHEN they look at the MainMenu sidebar
- THEN a "Formulieren" navigation item MUST be visible
- AND clicking it MUST navigate to the FormManager view

#### Scenario: FormManager shows key form information

- GIVEN 3 intake forms exist (2 active, 1 inactive)
- WHEN the user views the FormManager
- THEN each row MUST show: form name, active/inactive status badge, submission count
- AND each row MUST have actions: Bewerken, Insluitcode, Inzendingen, Activeren/Deactiveren

#### Scenario: Empty state when no forms exist

- GIVEN no intake forms have been created
- WHEN the user views FormManager
- THEN an empty state MUST display: "Nog geen formulieren aangemaakt"
- AND a "Nieuw formulier aanmaken" button MUST be visible

#### Scenario: Form count shown in manager

- GIVEN form "Contactformulier" has received 42 submissions
- WHEN the FormManager renders that row
- THEN the submission count MUST display "42"
- AND this value MUST be read from `intakeForm.submitCount`
