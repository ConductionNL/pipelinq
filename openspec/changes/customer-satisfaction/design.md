## Context

Pipelinq CRM on Nextcloud needs KTO/NPS capabilities to close the feedback loop between service delivery and client satisfaction measurement.

## Goals / Non-Goals

**Goals:** Survey CRUD, public response collection via token, NPS/rating calculation, survey analytics with CSV export, dashboard integration, entity linking to clients/leads.
**Non-Goals:** Email distribution automation, A/B testing, external survey tool integration, real-time response streaming.

## Decisions

1. **Two schemas** (`survey`, `surveyResponse`) in existing pipelinq register -- follows thin-client pattern where all data is stored via OpenRegister
2. **Embedded questions** as JSON array property on survey -- no separate question schema needed, keeps the model simple
3. **PublicSurveyController** extends `PublicShareController` for unauthenticated access via UUID token -- reuses Nextcloud's public share infrastructure
4. **On-the-fly NPS calculation** via Pinia computed getters -- no pre-aggregated scores, keeps data model simple
5. **Entity linking** via `linkedEntityType` + `entityId` on survey -- allows linking surveys to clients, leads, or requests
6. **IP hashing** on responses (SHA-256) -- privacy-preserving duplicate detection without storing raw IPs
7. **Brute force protection** on public endpoints via `@BruteForceProtection` annotation -- prevents token enumeration

## Data Model

### survey schema (schema:Survey)
| Property | Type | Description |
|----------|------|-------------|
| title | string | Survey title |
| description | string | Description shown to respondents |
| questions | array | Ordered list of question objects |
| status | string | draft / active / closed |
| token | string | UUID public access token |
| linkedEntityType | string | client / lead / request / null |
| linkedEntityId | string | UUID of linked entity |
| activeFrom | string | ISO date start |
| activeUntil | string | ISO date end |
| createdBy | string | Nextcloud user UID |
| createdAt | string | ISO datetime |
| updatedAt | string | ISO datetime |

### surveyResponse schema (schema:CompletedSurvey)
| Property | Type | Description |
|----------|------|-------------|
| surveyId | string | Reference to survey |
| answers | array | Answer objects with questionId, value |
| respondentId | string | Optional respondent identifier |
| entityType | string | Optional entity type context |
| entityId | string | Optional entity UUID context |
| completedAt | string | ISO datetime |
| ipHash | string | SHA-256 of respondent IP |

## Component Architecture

### Backend
- `PublicSurveyController` -- public endpoints for show (GET) and submit (POST) via token
- Routes: `/public/survey/{token}` (GET), `/public/survey/{token}/respond` (POST)
- `SettingsService` and `SchemaMapService` -- config keys for survey schemas

### Frontend
- `surveyStore` (Pinia) -- CRUD actions, NPS/satisfaction computed getters
- Views: `SurveyList`, `SurveyDetail`, `SurveyForm` (with QuestionEditor), `SurveyAnalytics`, `PublicSurveyForm`
- Routes: `/surveys`, `/surveys/new`, `/surveys/:id`, `/surveys/:id/edit`, `/surveys/:id/analytics`, `/public/survey/:token`

## Seed Data

### survey (3 objects)
1. **Klanttevredenheid Burgerzaken** -- active KTO survey for municipal services with NPS, rating, multiple choice, and open text questions. Status: active, linked to client entity type.
2. **Product Feedback Q1 2026** -- product satisfaction survey with star ratings and yes/no questions. Status: draft, no entity link.
3. **Afgerond Service Onderzoek** -- closed service quality survey. Status: closed, with activeUntil in the past.

### surveyResponse (5 objects)
1-3. Three responses to "Klanttevredenheid Burgerzaken" with varying NPS scores (9, 7, 3) and ratings (5, 4, 2) to enable meaningful NPS calculation.
4-5. Two responses to "Afgerond Service Onderzoek" with mixed ratings.

## Risks / Trade-offs

- Public endpoint abuse mitigated by brute force protection and UUID tokens (not sequential IDs)
- No email distribution (requires n8n integration, future scope)
- On-the-fly NPS calculation may be slow with very large response sets (>10K) -- acceptable for V1, can add caching later
