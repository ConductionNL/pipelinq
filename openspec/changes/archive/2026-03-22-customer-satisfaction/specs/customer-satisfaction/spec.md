# Customer Satisfaction Specification

## ADDED Requirements

### Requirement: Survey and SurveyResponse Schema Registration
The system MUST register `survey` and `surveyResponse` schemas in the pipelinq register with Schema.org types `schema:Survey` and `schema:CompletedSurvey`.

#### Scenario: Schema registration
- **WHEN** the repair step runs
- **THEN** both schemas MUST exist with all required properties

### Requirement: Survey CRUD Management
Admins MUST be able to create, read, update, and delete KTO surveys with configurable question types (NPS, rating, multiple choice, open text, yes/no).

#### Scenario: Create survey
- **WHEN** a user creates a survey
- **THEN** it MUST be saved with status "draft" and an auto-generated UUID token

### Requirement: Public Survey Response Collection
The system MUST provide a public endpoint for unauthenticated survey response submission via unique token URL.

#### Scenario: Submit response
- **WHEN** a respondent fills in required questions and submits
- **THEN** a surveyResponse object MUST be created in OpenRegister

### Requirement: NPS and Satisfaction Calculation
The system MUST calculate NPS (Promoters 9-10, Passives 7-8, Detractors 0-6) and average ratings from responses.

#### Scenario: NPS calculation
- **WHEN** responses contain NPS answers
- **THEN** NPS MUST be calculated as (% Promoters - % Detractors) displayed as -100 to +100

### Requirement: Survey Navigation
The system MUST include a "Surveys" menu item and routes for list, detail, analytics, and public form views.

#### Scenario: Navigation
- **WHEN** a user accesses Pipelinq
- **THEN** the Surveys menu item MUST be visible in the navigation
