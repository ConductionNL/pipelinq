# Customer Satisfaction (KTO/NPS)

**Status:** Implemented

## Overview

Klanttevredenheidsonderzoek (KTO) survey management and Net Promoter Score (NPS) tracking for Pipelinq. Enables organizations to measure client satisfaction after interactions, track trends over time, and surface satisfaction KPIs on the dashboard.

## Standards

- **GEMMA Klanttevredenheidcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-38f0aa7b-db82-4fbb-902d-81207116b0bc)
- **GEMMA Klantfeedbackcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-e06df156-e4b8-4ae5-a913-868bdf6eb0fb)
- **TEC CRM**: Section 4.1 (Analytics), Section 4.3 (Dashboard)
- **Schema.org**: `schema:Survey`, `schema:CompletedSurvey`

## Key Capabilities

### Survey Management
- Survey CRUD: create, configure, and manage KTO surveys via OpenRegister API
- Question types: NPS (0-10 scale), star rating (1-5), multiple choice, open text, yes/no
- Survey lifecycle: draft -> active -> closed with activeFrom/activeUntil date range
- Link surveys to entities via linkedEntityType (request, contactmoment, lead) + linkedEntityId

### Public Response Collection
- Public survey response endpoint via PublicSurveyController (extends PublicShareController)
- Unique token-based URLs: `/public/survey/{token}` (GET) and `/public/survey/{token}/respond` (POST)
- Brute force protection on both endpoints
- Validation: 404 for invalid token (with throttle), 410 for inactive/expired, 400 for missing answers, 503 when not configured
- Privacy: IP addresses stored as SHA-256 hash only

### NPS & Satisfaction Analytics
- NPS calculation: (Promoters 9-10 minus Detractors 0-6) / Total * 100, range -100 to +100
- Average satisfaction score from rating-type questions, rounded to one decimal
- Response count and completion rate tracking
- SurveyAnalytics view with per-question answer breakdown
- CSV export of response data

### Entity Linking
- Link survey responses to specific contactmomenten, requests, or clients
- Optional respondentId, entityType, entityId on responses

### Dashboard Integration
- Satisfaction KPI card in the main dashboard

## Data Model

Two schemas in `pipelinq_register.json`:

| Schema | Type | Key Fields |
|--------|------|-----------|
| `survey` | schema:Survey | title, description, questions[], status, token, linkedEntityType, linkedEntityId, activeFrom, activeUntil, createdBy |
| `surveyResponse` | schema:CompletedSurvey | surveyId, answers[], respondentId, entityType, entityId, completedAt, ipHash |

Seed data: 3 surveys (active, draft, closed) and 5 responses with varied NPS/rating values.

## Implementation

### Backend
- `lib/Controller/PublicSurveyController.php` -- public endpoints for survey show and response submission
- `lib/Service/SettingsService.php` -- survey_schema and surveyResponse_schema config keys
- `lib/Service/SchemaMapService.php` -- schema slug mappings
- `appinfo/routes.php` -- public survey routes

### Frontend
- `src/store/modules/survey.js` -- Pinia store with CRUD actions and NPS/satisfaction computed getters
- `src/views/surveys/` -- SurveyList, SurveyDetail, SurveyForm, SurveyAnalytics, PublicSurveyForm
- `src/router/index.js` -- 6 survey routes
- `src/navigation/MainMenu.vue` -- Surveys menu item

### Tests
- `tests/Unit/Controller/PublicSurveyControllerTest.php` -- 7 test methods covering show/submit scenarios

## Specification

Main specification: `openspec/specs/customer-satisfaction/spec.md`

Archived change: `openspec/changes/archive/2026-03-24-customer-satisfaction/`
