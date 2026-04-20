# Tasks: public-intake-forms

## 1. Schema Definition

- [x] 1.1 Add `intakeForm` and `intakeSubmission` schemas to `lib/Settings/pipelinq_register.json`.
- [x] 1.2 Register both schemas in the pipelinq register schemas array.

## 2. Backend Service

- [x] 2.1 Create `lib/Service/IntakeFormService.php` with submission processing, rate limiting, contact dedup, embed code generation, and CSV export.

## 3. Backend Controllers and Routes

- [x] 3.1 Create `lib/Controller/PublicFormController.php` with public form rendering and submission endpoints.
- [x] 3.2 Create `lib/Controller/IntakeFormController.php` with embed code, submissions list, and CSV export.
- [x] 3.3 Add public and management routes to `appinfo/routes.php`.

## 4. Frontend Store

- [x] 4.1 Register `intakeForm` and `intakeSubmission` object types in `src/store/store.js`.

## 5. Frontend Views

- [x] 5.1 Create `src/views/forms/FormManager.vue` with form list.
- [x] 5.2 Create `src/views/forms/FormBuilder.vue` with field builder and configuration.
- [x] 5.3 Create `src/views/forms/FormSubmissions.vue` with submission history.

## 6. Navigation and Routing

- [x] 6.1 Add form routes to `src/router/index.js`.
- [x] 6.2 Add Forms settings nav item to `src/navigation/MainMenu.vue`.
