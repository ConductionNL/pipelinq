# Tasks: campaign-events

### Task 1: Schemas
- **spec_ref**: `openspec/changes/campaign-events/specs/marketing-campaigns/spec.md#requirement-a-campaign-holds-events-people-register-for`
- **files**: `lib/Settings/register.d/98-campaign-events.json`
- [x] Implement: `campaignEvent`, `eventRegistration`
- [x] Test: `tests/vitest/campaignEvents.spec.js`

### Task 2: Pages
- **spec_ref**: `openspec/changes/campaign-events/specs/marketing-campaigns/spec.md#requirement-the-event-page-counts-its-registrations`
- **files**: `src/manifest.d/78-campaign-events.json`, `src/manifest.d/78-marketing-campaigns.json`
- [x] Implement: event page, events list, registrations list, Events card on the campaign page
- [x] Test: `tests/vitest/campaignEvents.spec.js`
