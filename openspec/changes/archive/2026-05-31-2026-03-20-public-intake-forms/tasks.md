# Tasks: public-intake-forms

## 1. Schema Definition

- [x] 1.1 Add `intakeForm` schema to `lib/Settings/pipelinq_register.json` with properties: name, fields (array), fieldMappings (object), targetPipeline, targetStage, notifyUser, isActive, submitCount, successMessage.
- [x] 1.2 Add `intakeSubmission` schema to `lib/Settings/pipelinq_register.json` with properties: form (uuid ref), submittedAt, data (object), contactId, leadId, ip, status (enum: processed/rejected/spam).
- [x] 1.3 Register both schemas in the `schemas` array of the pipelinq register definition.

## 2. Backend Service

- [x] 2.1 Create `lib/Service/IntakeFormService.php` with constructor DI for OpenRegister ObjectService, IAppConfig, IManager (notifications), and ILogger.
  - Add `@spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1` PHPDoc.
- [x] 2.2 Implement `processSubmission(array $formData, string $formId, string $ip): array` — validate required fields, check honeypot (`_hp`), check rate limit, deduplicate contact by email, create contact object, create lead object, record intakeSubmission, increment submitCount, notify user.
- [x] 2.3 Implement `checkRateLimit(string $ip, string $formId): bool` — use APCu key `pipelinq_ratelimit_{ip}_{formId}` with TTL 300s, max 5 submissions per window.
- [x] 2.4 Implement `deduplicateContact(string $email): ?array` — query OpenRegister for existing contact with matching email; return contact array or null.
- [x] 2.5 Implement `generateEmbedCode(string $formId, string $baseUrl): array` — return `['iframe' => '...', 'js' => '...']` using server base URL.
- [x] 2.6 Implement `exportSubmissionsCsv(string $formId): string` — fetch all intakeSubmission objects for the form, build CSV with header row (submittedAt, status, contactId, leadId, ip, plus all data keys), return CSV string.

## 3. Backend Controllers and Routes

- [x] 3.1 Create `lib/Controller/PublicFormController.php` with `#[PublicPage]` and `#[NoCSRFRequired]` attributes.
  - `getForm(string $id): JSONResponse` — return public-safe form fields only (name, fields, successMessage); 403 if inactive, 404 if not found.
  - `submitForm(string $id, Request $request): JSONResponse` — delegate to IntakeFormService::processSubmission; 429 if rate limited, 422 if validation fails, 200 on success.
  - `submitFormOptions(string $id): Response` — return CORS preflight headers.
  - Add `@spec` PHPDoc tags referencing REQ-IF-003 and REQ-IF-004.
- [x] 3.2 Create `lib/Controller/IntakeFormController.php` with `#[NoAdminRequired]`.
  - `getEmbedCode(string $id): JSONResponse` — call IntakeFormService::generateEmbedCode.
  - `listSubmissions(string $id, int $page, int $limit): JSONResponse` — query OpenRegister for intakeSubmission objects by form UUID.
  - `exportSubmissions(string $id): Response` — call IntakeFormService::exportSubmissionsCsv, return with Content-Type text/csv and Content-Disposition attachment header.
  - Add `@spec` PHPDoc tags.
- [x] 3.3 Add routes to `appinfo/routes.php` — public routes BEFORE any wildcard `{slug}` routes:
  ```php
  // Public (no auth)
  ['name' => 'PublicForm#getForm', 'url' => '/api/public/forms/{id}', 'verb' => 'GET'],
  ['name' => 'PublicForm#submitForm', 'url' => '/api/public/forms/{id}/submit', 'verb' => 'POST'],
  ['name' => 'PublicForm#submitFormOptions', 'url' => '/api/public/forms/{id}/submit', 'verb' => 'OPTIONS'],
  // Authenticated management
  ['name' => 'IntakeForm#getEmbedCode', 'url' => '/api/forms/{id}/embed', 'verb' => 'GET'],
  ['name' => 'IntakeForm#listSubmissions', 'url' => '/api/forms/{id}/submissions', 'verb' => 'GET'],
  ['name' => 'IntakeForm#exportSubmissions', 'url' => '/api/forms/{id}/submissions/export', 'verb' => 'GET'],
  ```

## 4. Frontend Store

- [x] 4.1 Register `intakeForm` object type in `src/store/store.js`:
  ```js
  objectStore.registerObjectType('intakeForm', 'intake-form', 'pipelinq')
  ```
- [x] 4.2 Register `intakeSubmission` object type in `src/store/store.js`:
  ```js
  objectStore.registerObjectType('intakeSubmission', 'intake-submission', 'pipelinq')
  ```

## 5. Frontend Views

- [x] 5.1 Create `src/views/forms/FormManager.vue`:
  - Use `CnIndexPage` with `useListView('intakeForm', { sidebarState, objectStore })`.
  - Columns: name, isActive (CnStatusBadge), submitCount.
  - Row actions: Bewerken (router.push to FormBuilder), Insluitcode (fetch embed code, open CnCopyDialog), Inzendingen (router.push to FormSubmissions), Activeren/Deactiveren (toggle isActive via objectStore.saveObject).
  - Empty state: "Nog geen formulieren aangemaakt" with "Nieuw formulier aanmaken" CTA.
- [x] 5.2 Create `src/views/forms/FormBuilder.vue`:
  - Props: `formId` from route (string, 'new' for create mode).
  - Sections: General (name, successMessage, isActive), Target (targetPipeline select, targetStage text), Notification (notifyUser), Fields (ordered list with type/label/required/placeholder/options, move-up/move-down buttons), Field Mappings (table mapping field name to entity property).
  - Save: `objectStore.saveObject('intakeForm', formData)` wrapped in try/catch with user-facing error toast.
  - Translations via `t(appName, 'text')` for all labels. No hardcoded strings.
- [x] 5.3 Create `src/views/forms/FormSubmissions.vue`:
  - Props: `formId` from route.
  - Fetch submissions via `objectStore.fetchCollection('intakeSubmission', { form: formId })`.
  - Table columns: submittedAt, status (CnStatusBadge), contactId (router link), leadId (router link), ip.
  - Header: form name + "Exporteren als CSV" button (hidden when submitCount === 0).
  - CSV export: call `GET /api/forms/{id}/submissions/export`, trigger browser download via Blob URL.
  - Empty state: "Nog geen inzendingen ontvangen".

## 6. Navigation and Routing

- [x] 6.1 Add form routes to `src/router/index.js`:
  ```js
  { path: '/forms', name: 'FormManager', component: FormManager },
  { path: '/forms/:id', name: 'FormBuilder', component: FormBuilder, props: route => ({ formId: route.params.id }) },
  { path: '/forms/:id/submissions', name: 'FormSubmissions', component: FormSubmissions, props: route => ({ formId: route.params.id }) },
  ```
- [x] 6.2 Add "Formulieren" navigation item to `src/navigation/MainMenu.vue`:
  - Icon: `mdi-form-select` (or equivalent MDI form icon via CnIcon).
  - Route: `{ name: 'FormManager' }`.
  - Translation key: `t(appName, 'Forms')` with Dutch: "Formulieren".
  - Import and register the new view components.
