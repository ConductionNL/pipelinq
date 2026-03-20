# Pipelinq Features

Feature documentation organized by functional group. Each file describes implemented and planned features based on the OpenSpec specifications. Screenshots are included for all implemented UI features.

## Feature Groups

| Feature | File | Screenshot | Specs |
|---------|------|------------|-------|
| Dashboard | [dashboard.md](dashboard.md) | [screenshot](../screenshots/dashboard.png) | dashboard |
| Client Management | [client-management.md](client-management.md) | [screenshot](../screenshots/client-management.png) | client-management, contacts-sync |
| Lead Management | [lead-management.md](lead-management.md) | [screenshot](../screenshots/lead-management.png) | lead-management |
| Request Management | [request-management.md](request-management.md) | [screenshot](../screenshots/request-management.png) | request-management |
| Pipeline & Kanban | [pipeline-kanban.md](pipeline-kanban.md) | [screenshot](../screenshots/pipeline.png) | pipeline, pipeline-insights |
| Product Catalog | [product-catalog.md](product-catalog.md) | [screenshot](../screenshots/product-catalog.png) | product-catalog, lead-product-link, product-catalog-quoting |
| My Work | [my-work.md](my-work.md) | [screenshot](../screenshots/my-work.png) | my-work |
| Collaboration | [collaboration.md](collaboration.md) | -- (backend) | entity-notes, notifications-activity |
| Administration | [administration.md](administration.md) | [screenshot](../screenshots/admin-settings.png) | admin-settings, openregister-integration |
| KCC Werkplek | [kcc-werkplek.md](kcc-werkplek.md) | -- (planned) | kcc-werkplek, contactmomenten-rapportage, omnichannel-registratie, klantbeeld-360, terugbel-taakbeheer, kennisbank |
| Integrations | [integrations.md](integrations.md) | -- (planned/backend) | crm-workflow-automation, email-calendar-sync, contact-relationship-mapping, prospect-discovery, public-intake-forms, register-i18n, prometheus-metrics, activity-timeline |

## Screenshots

All screenshots are stored in `docs/screenshots/` and captured from the running application at `http://localhost:8080`.

| Screenshot | Description |
|------------|-------------|
| [dashboard.png](../screenshots/dashboard.png) | Dashboard with KPI cards, status chart, and My Work preview |
| [client-management.png](../screenshots/client-management.png) | Client list view with Cards/Table toggle |
| [contacts.png](../screenshots/contacts.png) | Contacts list view for contact persons |
| [lead-management.png](../screenshots/lead-management.png) | Lead list view with Cards/Table toggle |
| [request-management.png](../screenshots/request-management.png) | Request list view with Cards/Table toggle |
| [pipeline.png](../screenshots/pipeline.png) | Pipeline kanban board with Sales Pipeline and sidebar |
| [product-catalog.png](../screenshots/product-catalog.png) | Product catalog list view |
| [my-work.png](../screenshots/my-work.png) | My Work view with filter tabs and Show completed toggle |
| [admin-settings.png](../screenshots/admin-settings.png) | Admin settings with version info, register config, pipeline management |

## Spec-to-Feature Mapping

Used by the `/opsx:archive` skill to update the correct feature doc when archiving a change.

```
client-management -> client-management.md
contacts-sync -> client-management.md
lead-management -> lead-management.md
request-management -> request-management.md
pipeline -> pipeline-kanban.md
pipeline-insights -> pipeline-kanban.md
dashboard -> dashboard.md
my-work -> my-work.md
entity-notes -> collaboration.md
notifications-activity -> collaboration.md
admin-settings -> administration.md
openregister-integration -> administration.md
product-catalog -> product-catalog.md
lead-product-link -> product-catalog.md
product-catalog-quoting -> product-catalog.md
kcc-werkplek -> kcc-werkplek.md
contactmomenten-rapportage -> kcc-werkplek.md
omnichannel-registratie -> kcc-werkplek.md
klantbeeld-360 -> kcc-werkplek.md
terugbel-taakbeheer -> kcc-werkplek.md
kennisbank -> kcc-werkplek.md
crm-workflow-automation -> integrations.md
email-calendar-sync -> integrations.md
contact-relationship-mapping -> integrations.md
prospect-discovery -> integrations.md
public-intake-forms -> integrations.md
register-i18n -> integrations.md
prometheus-metrics -> integrations.md
activity-timeline -> integrations.md
```
