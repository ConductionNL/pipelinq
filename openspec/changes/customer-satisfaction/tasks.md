## 1. Schema Registration

- [x] 1.1 Add survey schema to pipelinq_register.json
  - acceptance_criteria: survey schema exists with all properties (title, description, questions, status, token, linkedEntityType, linkedEntityId, activeFrom, activeUntil, createdBy, createdAt, updatedAt) and @type schema:Survey
  - spec_ref: specs/customer-satisfaction/spec.md#survey-and-surveyresponse-schema-registration
  - files: lib/Settings/pipelinq_register.json

- [x] 1.2 Add surveyResponse schema to pipelinq_register.json
  - acceptance_criteria: surveyResponse schema exists with all properties (surveyId, answers, respondentId, entityType, entityId, completedAt, ipHash) and @type schema:CompletedSurvey
  - spec_ref: specs/customer-satisfaction/spec.md#survey-and-surveyresponse-schema-registration
  - files: lib/Settings/pipelinq_register.json

- [x] 1.3 Add schema config keys to SettingsService and SchemaMapService
  - acceptance_criteria: SettingsService exposes survey_schema and surveyResponse_schema keys; SchemaMapService includes mappings for both schemas
  - spec_ref: specs/customer-satisfaction/spec.md#survey-and-surveyresponse-schema-registration
  - files: lib/Service/SettingsService.php, lib/Service/SchemaMapService.php

## 2. Backend

- [x] 2.1 Create PublicSurveyController
  - acceptance_criteria: Controller extends PublicShareController with show() and submit() methods; show returns survey data (excluding sensitive fields) for active surveys; submit creates surveyResponse via ObjectService
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: lib/Controller/PublicSurveyController.php

- [x] 2.2 Register public routes
  - acceptance_criteria: Routes exist for GET /public/survey/{token} and POST /public/survey/{token}/respond
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: appinfo/routes.php

- [x] 2.3 Implement token validation and status checks
  - acceptance_criteria: Returns 404 with throttle for invalid token; returns 410 for inactive/expired survey; returns 400 for missing answers; returns 503 when not configured
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: lib/Controller/PublicSurveyController.php

- [x] 2.4 Add brute force protection
  - acceptance_criteria: @BruteForceProtection annotation on show (action=pipelinq_survey) and submit (action=pipelinq_survey_submit)
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: lib/Controller/PublicSurveyController.php

## 3. Frontend Store

- [x] 3.1 Create surveyStore with CRUD actions
  - acceptance_criteria: Pinia store with fetchSurveys, fetchSurvey, createSurvey, updateSurvey, deleteSurvey, fetchResponses, submitPublicResponse actions
  - spec_ref: specs/customer-satisfaction/spec.md#survey-crud-management
  - files: src/store/modules/survey.js

- [x] 3.2 Add NPS and satisfaction computed getters
  - acceptance_criteria: npsScore getter calculates (Promoters-Detractors)/Total*100; satisfactionAverage getter returns mean of ratings; both return null with no responses
  - spec_ref: specs/customer-satisfaction/spec.md#nps-and-satisfaction-calculation
  - files: src/store/modules/survey.js

- [x] 3.3 Register store in store.js
  - acceptance_criteria: surveyStore is importable and initialized via store.js
  - spec_ref: specs/customer-satisfaction/spec.md#survey-crud-management
  - files: src/store/store.js

## 4. Survey Views

- [x] 4.1 Create SurveyList view
  - acceptance_criteria: Lists all surveys with title, status, response count; supports navigation to detail/create
  - spec_ref: specs/customer-satisfaction/spec.md#survey-crud-management
  - files: src/views/surveys/SurveyList.vue

- [x] 4.2 Create SurveyDetail view
  - acceptance_criteria: Shows survey details, questions, link to analytics, edit button
  - spec_ref: specs/customer-satisfaction/spec.md#survey-crud-management
  - files: src/views/surveys/SurveyDetail.vue

- [x] 4.3 Create SurveyForm with QuestionEditor
  - acceptance_criteria: Form for create/edit with question type selector (nps, rating, multiple_choice, open_text, yes_no), label, required flag, options for multiple choice
  - spec_ref: specs/customer-satisfaction/spec.md#question-types
  - files: src/views/surveys/SurveyForm.vue

- [x] 4.4 Create SurveyAnalytics with CSV export
  - acceptance_criteria: Shows NPS score, satisfaction average, response count, completion rate, answer breakdown per question; CSV export button generates downloadable file
  - spec_ref: specs/customer-satisfaction/spec.md#survey-analytics
  - files: src/views/surveys/SurveyAnalytics.vue

- [x] 4.5 Create PublicSurveyForm
  - acceptance_criteria: Renders survey questions for unauthenticated respondents; submits via public API; shows thank-you message on success; shows error for expired/invalid surveys
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: src/views/surveys/PublicSurveyForm.vue

## 5. Navigation and Routing

- [x] 5.1 Add Surveys menu item to navigation
  - acceptance_criteria: "Surveys" menu item visible in Pipelinq navigation for authenticated users
  - spec_ref: specs/customer-satisfaction/spec.md#survey-navigation
  - files: src/navigation/MainMenu.vue

- [x] 5.2 Add survey routes to router
  - acceptance_criteria: Routes exist for /surveys, /surveys/new, /surveys/:id, /surveys/:id/edit, /surveys/:id/analytics, /public/survey/:token
  - spec_ref: specs/customer-satisfaction/spec.md#survey-navigation
  - files: src/router/index.js

## 6. Testing

- [x] 6.1 Write PublicSurveyController unit tests
  - acceptance_criteria: Tests for show (active, inactive, not found), submit (success, missing answers, not configured), token validation; minimum 3 test methods
  - spec_ref: specs/customer-satisfaction/spec.md#public-survey-response-collection
  - files: tests/Unit/Controller/PublicSurveyControllerTest.php

## 7. Seed Data

- [x] 7.1 Add survey and surveyResponse seed data
  - acceptance_criteria: 3 survey objects (active, draft, closed) and 5 response objects with varied NPS/rating values per design.md seed data section
  - spec_ref: specs/customer-satisfaction/spec.md#survey-and-surveyresponse-schema-registration
  - files: lib/Settings/pipelinq_register.json
