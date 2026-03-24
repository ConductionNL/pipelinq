# Customer Satisfaction Specification

## Purpose

Enable KTO (klanttevredenheidsonderzoek) surveys and NPS measurement in Pipelinq CRM. Allows organizations to create surveys with configurable question types, collect responses via public token URLs, and analyze satisfaction metrics including NPS scores.

---

## Requirements

### Requirement: Survey and SurveyResponse Schema Registration

The system MUST register `survey` and `surveyResponse` schemas in the pipelinq register with Schema.org types `schema:Survey` and `schema:CompletedSurvey`.

#### Scenario: Schema registration on repair step
- **GIVEN** the pipelinq app is installed
- **WHEN** the repair step runs and imports `pipelinq_register.json`
- **THEN** the `survey` schema MUST exist with properties: title, description, questions (array), status (draft/active/closed), token (UUID), linkedEntityType, linkedEntityId, activeFrom, activeUntil, createdBy, createdAt, updatedAt
- **AND** the `surveyResponse` schema MUST exist with properties: surveyId, answers (array), respondentId, entityType, entityId, completedAt, ipHash

#### Scenario: Schema config keys
- **WHEN** the schemas are registered
- **THEN** `SettingsService` MUST expose `survey_schema` and `surveyResponse_schema` config keys
- **AND** `SchemaMapService` MUST include mappings for both schemas

### Requirement: Survey CRUD Management

Admins MUST be able to create, read, update, and delete KTO surveys with configurable question types: NPS (0-10 scale), star rating (1-5), multiple choice, open text, and yes/no.

#### Scenario: Create survey
- **GIVEN** an authenticated user
- **WHEN** the user creates a survey via the SurveyForm view
- **THEN** the survey MUST be saved via OpenRegister API with status "draft" and an auto-generated UUID token
- **AND** the survey MUST appear in the SurveyList view

#### Scenario: Edit survey
- **GIVEN** an existing survey
- **WHEN** the user navigates to `/surveys/:id/edit`
- **THEN** the SurveyForm MUST load the existing survey data for editing
- **AND** saving MUST update the survey via OpenRegister PUT

#### Scenario: Delete survey
- **GIVEN** an existing survey in the list
- **WHEN** the user deletes the survey
- **THEN** the survey MUST be removed via OpenRegister DELETE
- **AND** the survey MUST disappear from the SurveyList

### Requirement: Question Types

Each survey question MUST have an `id`, `label`, `type`, and `required` flag. Supported types:
- `nps`: Net Promoter Score 0-10 scale
- `rating`: Star rating 1-5
- `multiple_choice`: Options array with single selection
- `open_text`: Free-form text input
- `yes_no`: Boolean yes/no selection

#### Scenario: Question editor in survey form
- **GIVEN** the user is creating or editing a survey
- **WHEN** the user adds a question
- **THEN** the QuestionEditor component MUST allow selecting question type, label, required flag, and options (for multiple choice)

### Requirement: Public Survey Response Collection

The system MUST provide a public endpoint for unauthenticated survey response submission via unique token URL.

#### Scenario: View public survey
- **GIVEN** a survey with status "active" and a valid token
- **WHEN** a respondent visits `/public/survey/{token}`
- **THEN** the PublicSurveyController MUST return the survey data (excluding sensitive fields like createdBy, linkedEntityId)
- **AND** the PublicSurveyForm component MUST render the questions

#### Scenario: Submit public response
- **GIVEN** a respondent has filled in the required questions
- **WHEN** the respondent submits the form
- **THEN** a `surveyResponse` object MUST be created in OpenRegister with answers, completedAt timestamp, and IP hash
- **AND** the response MUST return HTTP 201 with a thank-you message

#### Scenario: Expired or inactive survey
- **GIVEN** a survey with status "closed" or past `activeUntil` date
- **WHEN** a respondent accesses the token URL
- **THEN** the system MUST return HTTP 410 Gone with message "This survey is no longer accepting responses"

#### Scenario: Invalid token
- **GIVEN** an invalid or non-existent token
- **WHEN** a respondent accesses the URL
- **THEN** the system MUST return HTTP 404 with brute force throttling

#### Scenario: Missing answers
- **GIVEN** a valid active survey token
- **WHEN** a respondent submits without answers
- **THEN** the system MUST return HTTP 400 "Answers are required"

### Requirement: NPS and Satisfaction Calculation

The surveyStore MUST calculate NPS and average satisfaction from responses using computed getters.

#### Scenario: NPS calculation
- **GIVEN** responses contain NPS-type answers (0-10 scale)
- **WHEN** the `npsScore` getter is computed
- **THEN** NPS MUST be calculated as `round(((countPromoters - countDetractors) / totalResponses) * 100)`
- **AND** Promoters are scores 9-10, Passives 7-8, Detractors 0-6
- **AND** the result range is -100 to +100

#### Scenario: Satisfaction average
- **GIVEN** responses contain rating-type answers (1-5 scale)
- **WHEN** the `satisfactionAverage` getter is computed
- **THEN** the average MUST be calculated as mean of all rating values, rounded to one decimal

#### Scenario: No responses
- **GIVEN** no responses exist for the current survey
- **WHEN** NPS or satisfaction getters are computed
- **THEN** they MUST return null

### Requirement: Survey Analytics

The SurveyAnalytics view MUST display response statistics and support CSV export.

#### Scenario: Analytics view
- **GIVEN** a survey with responses
- **WHEN** the user navigates to `/surveys/:id/analytics`
- **THEN** the view MUST show: NPS score, average satisfaction, response count, completion rate
- **AND** a breakdown of answers per question

#### Scenario: CSV export
- **GIVEN** the analytics view is open
- **WHEN** the user clicks the export button
- **THEN** a CSV file MUST be generated with one row per response and columns for each question

### Requirement: Survey Navigation

The system MUST include a "Surveys" menu item and routes for all survey views.

#### Scenario: Navigation menu
- **GIVEN** an authenticated user in Pipelinq
- **WHEN** the user views the navigation
- **THEN** a "Surveys" menu item MUST be visible

#### Scenario: Routes
- **WHEN** the Vue router is configured
- **THEN** these routes MUST exist: `/surveys` (list), `/surveys/new` (create), `/surveys/:id` (detail), `/surveys/:id/edit` (edit), `/surveys/:id/analytics` (analytics), `/public/survey/:token` (public form)
