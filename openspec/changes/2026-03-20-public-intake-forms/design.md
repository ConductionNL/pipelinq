# Design: public-intake-forms

**Status:** pr-created

## Architecture

### Data Model

New schema `intakeForm` in the pipelinq register:

| Property | Type | Description |
|----------|------|-------------|
| name | string | Form name |
| fields | array | Ordered list of field definitions |
| targetPipeline | string (uuid) | Pipeline where new leads are placed |
| targetStage | string | Initial stage name for new leads |
| notifyUser | string | Nextcloud user to notify on submission |
| isActive | boolean | Whether the form accepts submissions |
| submitCount | integer | Total submissions received |
| fieldMappings | object | Maps form field names to entity properties |
| successMessage | string | Message shown after successful submission |

New schema `intakeSubmission` for submission records:

| Property | Type | Description |
|----------|------|-------------|
| form | string (uuid) | Reference to the intakeForm |
| submittedAt | string (datetime) | Submission timestamp |
| data | object | Submitted form data |
| contactId | string (uuid) | Created/matched contact |
| leadId | string (uuid) | Created lead |
| ip | string | Submitter IP (for rate limiting audit) |
| status | string | processed/rejected/spam |

### Backend

#### IntakeFormService (`lib/Service/IntakeFormService.php`)

- **processSubmission(array $formData, array $submission, string $ip)**: Validate submission, check honeypot, check rate limit, deduplicate contact by email, create contact + lead, record submission, notify user.
- **checkRateLimit(string $ip, string $formId)**: Check APCu for submission count from IP within 5 minutes.
- **deduplicateContact(string $email)**: Search existing contacts by email, return match or null.
- **generateEmbedCode(string $formId, string $baseUrl)**: Generate iframe and JS embed snippets.
- **exportSubmissionsCsv(string $formId)**: Generate CSV download of all submissions.

#### PublicFormController (`lib/Controller/PublicFormController.php`)

Public (no-auth) endpoints:

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/public/forms/{id}` | Get form definition (for rendering) |
| POST | `/api/public/forms/{id}/submit` | Submit form data |

Uses `#[PublicPage]` attribute for Nextcloud public routes. CORS headers for cross-origin embedding.

#### IntakeFormController (`lib/Controller/IntakeFormController.php`)

Authenticated management endpoints:

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/forms/{id}/embed` | Get embed code |
| GET | `/api/forms/{id}/submissions` | List submissions |
| GET | `/api/forms/{id}/submissions/export` | Export CSV |

### Frontend

#### FormManager.vue (`src/views/forms/FormManager.vue`)

List of all intake forms with: name, status, submission count, actions (edit, embed code, submissions, deactivate).

#### FormBuilder.vue (`src/views/forms/FormBuilder.vue`)

Form builder with:
- Name, success message
- Drag-and-drop field list (type, label, required, placeholder, options for select)
- Field-to-entity property mapping
- Target pipeline/stage selection
- Notification user selection

#### FormSubmissions.vue (`src/views/forms/FormSubmissions.vue`)

Submission history table with export CSV button.

## Files Changed

- `lib/Settings/pipelinq_register.json` (modified -- add intakeForm and intakeSubmission schemas)
- `lib/Service/IntakeFormService.php` (new)
- `lib/Controller/PublicFormController.php` (new)
- `lib/Controller/IntakeFormController.php` (new)
- `appinfo/routes.php` (modified -- add public form routes and management routes)
- `src/store/store.js` (modified -- register intakeForm and intakeSubmission object types)
- `src/router/index.js` (modified -- add form routes)
- `src/navigation/MainMenu.vue` (modified -- add Forms settings nav item)
- `src/views/forms/FormManager.vue` (new)
- `src/views/forms/FormBuilder.vue` (new)
- `src/views/forms/FormSubmissions.vue` (new)
