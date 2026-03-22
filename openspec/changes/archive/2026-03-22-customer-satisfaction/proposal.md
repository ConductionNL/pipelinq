## Why

Pipelinq tracks client interactions but cannot measure satisfaction. KTO surveys and NPS are required for government service quality and commercial CRM.

## What Changes

- New `survey` and `surveyResponse` schemas in OpenRegister
- Survey CRUD management UI, public response collection, NPS/satisfaction analytics
- Dashboard integration with satisfaction KPI card and trend widget

## Capabilities

### New Capabilities
- `customer-satisfaction`: KTO survey CRUD, public response collection, NPS calculation, analytics, entity linking, dashboard widgets

### Modified Capabilities
- `dashboard`: Add satisfaction KPI card and trend widget

## Impact

- Data model: Two new schemas in `pipelinq_register.json`
- Backend: PublicSurveyController for unauthenticated response submission
- Frontend: Survey views, store, dashboard widgets, navigation integration
- Feature tier: V1
