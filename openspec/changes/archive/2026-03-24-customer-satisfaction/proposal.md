## Why

Pipelinq tracks client interactions but cannot measure satisfaction. KTO (klanttevredenheidsonderzoek) surveys and NPS measurement are required for government service quality monitoring and commercial CRM best practices. This closes the feedback loop between service delivery and client experience.

## What Changes

- New `survey` and `surveyResponse` schemas in OpenRegister via `pipelinq_register.json`
- Survey CRUD management UI with configurable question types (NPS 0-10, star rating, multiple choice, open text, yes/no)
- Public response collection endpoint for unauthenticated survey submission via unique token URL
- NPS and satisfaction score calculation in Pinia store (computed getters)
- Survey analytics view with response breakdown and CSV export
- Dashboard integration with satisfaction KPI card and trend widget
- Navigation and routing for survey list, detail, form, analytics, and public form views

## Capabilities

### New Capabilities
- `customer-satisfaction`: KTO survey CRUD, public response collection via token, NPS calculation (Promoters 9-10, Passives 7-8, Detractors 0-6), satisfaction analytics with CSV export, entity linking, and navigation integration

### Modified Capabilities
- `dashboard`: Add satisfaction KPI card and NPS trend widget to dashboard grid

## Impact

- Data model: Two new schemas (`survey`, `surveyResponse`) added to `lib/Settings/pipelinq_register.json`
- Backend: `PublicSurveyController` extends `PublicShareController` for unauthenticated response submission with brute force protection
- Frontend: Survey views (list, detail, form, analytics, public form), `surveyStore` Pinia module, router routes, navigation menu item
- Schema mapping: `SchemaMapService` and `SettingsService` updated with survey/surveyResponse config keys
- Feature tier: V1
- Procest impact: None (surveys are CRM-side only)
