## Context

Pipelinq CRM on Nextcloud needs KTO/NPS capabilities to close the feedback loop.

## Goals / Non-Goals

**Goals:** Survey CRUD, public response collection, NPS/rating calculation, dashboard integration, entity linking.
**Non-Goals:** Email distribution automation, A/B testing, external survey tool integration.

## Decisions

1. **Two schemas** (`survey`, `surveyResponse`) in existing register — follows thin-client pattern
2. **Embedded questions** as JSON array — no separate question schema needed
3. **PublicSurveyController** for unauthenticated access via UUID token
4. **On-the-fly NPS calculation** — no pre-aggregated scores
5. **Entity linking** via `linkedEntityType` + `entityId` on responses

## Risks / Trade-offs

- Public endpoint abuse mitigated by brute force protection and UUID tokens
- No email distribution (requires n8n integration, future scope)
