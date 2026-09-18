# Customer satisfaction (KTO/NPS)

**Status:** the closed loop is implemented. A survey is sent on a per-recipient
token, answered once, classified, and an unhappy answer raises a follow-up task
with somebody's name on it.

What is implemented:

- dispatch rules, administered rather than coded: a trigger, a survey, a
  channel, a delay, a cooldown and a token lifetime
- an invitation per recipient, with its own token, persisted whether it was
  sent or suppressed, and the reason it was suppressed
- survey fatigue throttling per contact across surveys, and a permanent opt-out
  the respondent can set from the form itself
- single-use tokens: a second submission is refused, and an expired one opens a
  closed page rather than an error
- detractor follow-up: a task for the client's owner, or for the configured
  default assignee when the client has none, with the notification produced by
  the OpenRegister notification engine rather than by app code
- response-rate analytics, with suppressed and failed invitations reported
  beside the percentage rather than folded into it
- a per-client satisfaction panel on customer 360, with its own empty state

What is not built yet: the admin surface for the dispatch rules is the settings
API rather than a screen, and sms and whatsapp delivery wait on the outbound
channel adapters being configured.

## Overview

Klanttevredenheidsonderzoek (KTO) survey management and Net Promoter Score (NPS) tracking for Pipelinq. Enables organizations to measure client satisfaction after interactions, track trends over time, and surface satisfaction KPIs on the dashboard.

## Standards

- **GEMMA Klanttevredenheidcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-38f0aa7b-db82-4fbb-902d-81207116b0bc)
- **GEMMA Klantfeedbackcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-e06df156-e4b8-4ae5-a913-868bdf6eb0fb)
- **TEC CRM**: Section 4.1 (Analytics), Section 4.3 (Dashboard)

## Key Capabilities

### Survey Management
- Survey CRUD: create, configure, and manage KTO surveys
- Question types: NPS (0–10 scale), satisfaction rating (1–5), open text, multiple choice
- Survey lifecycle: draft → active → closed
- Link surveys to specific interaction channels, request types, or time periods

### Public Response Collection
- Public survey response endpoint (unauthenticated): citizens respond without login
- Unique survey links per contactmoment or request
- Rate limiting and duplicate submission prevention

### NPS & Satisfaction Analytics
- NPS calculation: promoters (9–10) minus detractors (0–6), displayed as −100 to +100
- Average satisfaction score per survey, per channel, per period
- Trend visualization: satisfaction over time
- Response rate tracking

### Entity Linking
- Link survey responses to specific contactmomenten, requests, or clients
- Aggregate satisfaction per client for 360-degree client view

### Dashboard Integration
- Satisfaction KPI card in the main dashboard: current NPS + trend arrow
- Satisfaction trend widget showing last 12 periods

## Data Model

Two new schemas in `pipelinq_register.json`:

| Schema | Key Fields |
|--------|-----------|
| `survey` | title, description, questions[], status, targetEntity, period |
| `surveyResponse` | surveyRef, respondent (optional), answers[], submittedAt, entityRef |

## Impact

- **Backend**: `PublicSurveyController` for unauthenticated response submission
- **Frontend**: Survey management views, response analytics view, dashboard widgets, navigation integration
- **Data model**: Two new schemas in `lib/Settings/pipelinq_register.json`

## Specification

Full specification: `openspec/changes/archive/2026-03-22-customer-satisfaction/specs/`

Related changes:
- Design: `openspec/changes/archive/2026-03-22-customer-satisfaction/design.md`
- Tasks: `openspec/changes/archive/2026-03-22-customer-satisfaction/tasks.md`
