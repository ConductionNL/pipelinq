# Proposal: public-intake-forms

## Problem

Pipelinq has no way to capture leads from external websites. There are no public form endpoints, no form builder, no embed code generation, and no spam protection. Organizations cannot create web-to-lead intake forms that feed into the CRM pipeline.

## Solution

Implement a public intake forms system with:
1. **IntakeForm schema** in OpenRegister for form definitions (fields, styling, target pipeline)
2. **IntakeSubmission schema** for recording all submissions
3. **PublicFormController** with public (no-auth) endpoints for rendering and submitting forms
4. **IntakeFormController** for managing forms (CRUD, embed code, submission history)
5. **IntakeFormService** for form rendering, submission processing, contact deduplication, and lead creation
6. **Form builder UI** for creating and managing intake forms
7. **Spam protection**: honeypot field, rate limiting
8. **Embed code** generation (iframe + JS snippet)

## Scope

- Form CRUD (create, edit, activate/deactivate, delete)
- Field types: text, textarea, email, phone, select, checkbox, file, hidden
- Field-to-entity property mapping
- Public form rendering endpoint (no auth required)
- Public form submission endpoint (creates contact + lead)
- Contact deduplication by email
- Honeypot spam protection
- Rate limiting per IP
- Embed code generation (iframe, JS snippet)
- Submission history with CSV export
- Form list management view

## Out of scope

- CAPTCHA integration (V1)
- Multi-step wizard forms (V1)
- Conditional field visibility (V1)
- Custom styling configuration (V1 -- basic styling only)



## Design

# Design: public-intake-forms

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



## Tasks

# Tasks: public-intake-forms

## 1. Schema Definition

- [ ] 1.1 Add `intakeForm` and `intakeSubmission` schemas to `lib/Settings/pipelinq_register.json`.
- [ ] 1.2 Register both schemas in the pipelinq register schemas array.

## 2. Backend Service

- [ ] 2.1 Create `lib/Service/IntakeFormService.php` with submission processing, rate limiting, contact dedup, embed code generation, and CSV export.

## 3. Backend Controllers and Routes

- [ ] 3.1 Create `lib/Controller/PublicFormController.php` with public form rendering and submission endpoints.
- [ ] 3.2 Create `lib/Controller/IntakeFormController.php` with embed code, submissions list, and CSV export.
- [ ] 3.3 Add public and management routes to `appinfo/routes.php`.

## 4. Frontend Store

- [ ] 4.1 Register `intakeForm` and `intakeSubmission` object types in `src/store/store.js`.

## 5. Frontend Views

- [ ] 5.1 Create `src/views/forms/FormManager.vue` with form list.
- [ ] 5.2 Create `src/views/forms/FormBuilder.vue` with field builder and configuration.
- [ ] 5.3 Create `src/views/forms/FormSubmissions.vue` with submission history.

## 6. Navigation and Routing

- [ ] 6.1 Add form routes to `src/router/index.js`.
- [ ] 6.2 Add Forms settings nav item to `src/navigation/MainMenu.vue`.