# Pipelinq — Feature Overview

Pipelinq is a Nextcloud CRM and customer interaction app for municipal KCC (Klant Contact Centrum) and commercial sales teams. It is built as a thin client on top of OpenRegister, providing UI/UX while OpenRegister handles all data persistence.

## Standards Compliance

| Standard | Reference | Status |
|----------|-----------|--------|
| GEMMA Relatiebeheercomponent (CRM) | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-eb436669-87b4-4134-b59b-dbfda11de5bc) | Implemented |
| GEMMA Callcentercomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-9d127615-3b66-4d9e-9071-2a85f9cd44d8) | Partial |
| GEMMA Klachten- en meldingencomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-d2d0679e-1fe3-4ec3-9b56-e11d693d1408) | Planned |
| GEMMA Klanttevredenheidcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-38f0aa7b-db82-4fbb-902d-81207116b0bc) | Planned |
| GEMMA Klantgeleidingcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-4fb80905-d79b-4cde-aeab-7459fec668b1) | Planned |
| GEMMA Klantfeedbackcomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-e06df156-e4b8-4ae5-a913-868bdf6eb0fb) | Planned |
| GEMMA Sociale mediacomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-d477e1d3-bf92-4b6f-b08d-78348dd0360f) | Planned |
| GEMMA Mediamonitor- en webcarecomponent | [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-dcdd3ea0-730b-445e-90f6-17eb664dd1df) | Planned |
| TEC CRM — Sales Force Automation (1) | Sections 1.1–1.12 | Partial |
| TEC CRM — Customer Service and Support (3) | Sections 3.1–3.6 | Partial |
| TEC CRM — Analytics and Reporting (4) | Sections 4.1–4.3 | Partial |
| TEC CRM — Extended CRM (5) | Sections 5.1–5.4 | Partial |
| VNG Klantinteracties API | [vng-realisatie.github.io](https://vng-realisatie.github.io/klantinteracties/) | Partial |
| Schema.org + vCard RFC 6350 | International data model | Implemented |

## Feature Index

| Feature | Summary | Status | Standards | Docs |
|---------|---------|--------|-----------|------|
| Client Management | CRUD for persons and organizations, contact persons, Nextcloud Contacts sync | Implemented | GEMMA CRM; TEC 1.4; Schema.org Organization/Person | [client-management.md](client-management.md) |
| Lead Management | Sales opportunity tracking through configurable pipeline stages | Implemented | GEMMA CRM; TEC 1.1, 1.3; Schema.org Offer | [lead-management.md](lead-management.md) |
| Request Management | Service intake and citizen inquiry handling before case conversion | Implemented | GEMMA Callcenter; TEC 3.1–3.4; VNG Verzoeken | [request-management.md](request-management.md) |
| Pipeline & Kanban | Configurable kanban boards with stage management for leads and requests | Implemented | TEC 1.10; GEMMA CRM | [pipeline-kanban.md](pipeline-kanban.md) |
| Dashboard | KPI cards, charts, activity preview, quick actions | Implemented | TEC 4.3 | [dashboard.md](dashboard.md) |
| My Work | Personal work queue aggregating all assigned items | Implemented | TEC 1.5 | [my-work.md](my-work.md) |
| Contacts Sync | Two-way sync with Nextcloud Contacts via IManager | Implemented | vCard RFC 6350 | [contacts-sync.md](contacts-sync.md) |
| Entity Notes | Internal notes and comments on all CRM entities | Implemented | TEC 5.3 | [entity-notes.md](entity-notes.md) |
| Notifications & Activity | Nextcloud activity events and push notifications on CRM changes | Implemented | Nextcloud Activity API | [notifications-activity.md](notifications-activity.md) |
| Activity Timeline | Chronological event timeline on entity detail views | Implemented | TEC 1.5 | [activity-timeline.md](activity-timeline.md) |
| Product & Service Catalog | Product/service catalogue with pricing for linking to leads | Implemented | TEC 1.8; Schema.org Product | [product-service-catalog.md](product-service-catalog.md) |
| Lead–Product Link | Line items linking leads to products/services with quantity and value | Implemented | TEC 1.8 | [lead-product-link.md](lead-product-link.md) |
| Prospect Discovery | ICP-based prospect search using KVK/NHR data for lead generation | Implemented | TEC 1.3 | [prospect-discovery.md](prospect-discovery.md) |
| OpenRegister Integration | Foundational integration: all data stored as register objects in OpenRegister | Implemented | — | [openregister-integration.md](openregister-integration.md) |
| Register i18n | Multilingual register/schema labels (Dutch + English) | Implemented | — | [register-i18n.md](register-i18n.md) |
| Admin Settings | Nextcloud admin panel for pipeline, schema, and app configuration | Implemented | — | [admin-settings.md](admin-settings.md) |
| Prometheus Metrics | Metrics endpoint for observability and monitoring | Implemented | — | [prometheus-metrics.md](prometheus-metrics.md) |
| Contact Relationship Mapping | Relationships between contacts and organizations beyond direct client link | Partially Implemented | GEMMA CRM; Schema.org | [contact-relationship-mapping.md](contact-relationship-mapping.md) |
| Omnichannel Registration | Channel-aware interaction registration (phone, email, counter, chat) | Partially Implemented | GEMMA Callcenter; VNG Klantinteracties | [omnichannel-registratie.md](omnichannel-registratie.md) |
| Pipeline Insights | Stage revenue summaries, stale lead detection, aging indicators | Planned | TEC 4.1, 4.2 | [pipeline-insights.md](pipeline-insights.md) |
| Contactmomenten | Core CRUD and lifecycle for registered client interaction records | Planned | GEMMA Callcenter; VNG Klantinteracties | [contactmomenten.md](contactmomenten.md) |
| Contactmomenten Rapportage | Management dashboards and KPI reporting on contact moments | Planned | GEMMA Callcenter; TEC 4.1–4.2 | [contactmomenten-rapportage.md](contactmomenten-rapportage.md) |
| Klantbeeld 360 | Unified 360-degree citizen/client profile view | Planned | GEMMA CRM; TEC 1.4 | [klantbeeld-360.md](klantbeeld-360.md) |
| Kennisbank | Searchable knowledge base for KCC agents with article lifecycle | Planned | GEMMA Callcenter; TEC 3.5 | [kennisbank.md](kennisbank.md) |
| Terugbel- & Taakbeheer | Callback requests and follow-up task assignment with deadline tracking | Planned | GEMMA Callcenter; TEC 3.2–3.3 | [terugbel-taakbeheer.md](terugbel-taakbeheer.md) |
| Queue Management | Priority queues and skill-based routing for requests and leads | Planned | GEMMA Klantgeleiding; TEC 3.2 | [queue-management.md](queue-management.md) |
| Klachtenregistratie | Complaint registration, categorization, SLA tracking, and audit trail | Planned | GEMMA Klachten- en meldingen; TEC 3.1–3.4 | [klachtenregistratie.md](klachtenregistratie.md) |
| Customer Satisfaction (KTO) | Survey management, NPS calculation, and satisfaction analytics | Planned | GEMMA Klanttevredenheid; TEC 4.1 | [customer-satisfaction.md](customer-satisfaction.md) |
| KCC Werkplek | Integrated KCC agent workstation view for municipal customer service | Planned | GEMMA Callcenter; GEMMA Klantgeleiding | [kcc-werkplek.md](kcc-werkplek.md) |
| CRM Workflow Automation | n8n-powered automation rules triggered on CRM events | Planned | TEC 5.2 | [crm-workflow-automation.md](crm-workflow-automation.md) |
| Email & Calendar Sync | Sync emails and calendar events to CRM entities | Planned | TEC 5.1; vCard RFC 6350 | [email-calendar-sync.md](email-calendar-sync.md) |
| Public Intake Forms | Public-facing intake forms for citizen self-service | Planned | GEMMA Klantgeleiding; TEC 3.6 | [public-intake-forms.md](public-intake-forms.md) |
| Product Catalog Quoting | Quotation generation from product catalog line items | Planned | TEC 1.8 | [product-catalog-quoting.md](product-catalog-quoting.md) |

## Feature Groups

### Core CRM (MVP — Implemented)

The foundational sales and relationship management layer. These features are live in the `development` branch.

| Feature | Docs |
|---------|------|
| Client Management | [client-management.md](client-management.md) |
| Lead Management | [lead-management.md](lead-management.md) |
| Pipeline & Kanban | [pipeline-kanban.md](pipeline-kanban.md) |
| Request Management | [request-management.md](request-management.md) |
| Dashboard | [dashboard.md](dashboard.md) |
| My Work | [my-work.md](my-work.md) |
| Contacts Sync | [contacts-sync.md](contacts-sync.md) |
| Entity Notes | [entity-notes.md](entity-notes.md) |
| Notifications & Activity | [notifications-activity.md](notifications-activity.md) |
| Activity Timeline | [activity-timeline.md](activity-timeline.md) |
| Product & Service Catalog | [product-service-catalog.md](product-service-catalog.md) |
| Lead–Product Link | [lead-product-link.md](lead-product-link.md) |
| Prospect Discovery | [prospect-discovery.md](prospect-discovery.md) |

### KCC / Government (V1)

Features required for Dutch municipal KCC-werkplek deployments. Demand validated across 39K+ government tenders.

| Feature | Market Demand | Docs |
|---------|---------------|------|
| Contactmomenten | 54% of klantinteractie tenders | [contactmomenten.md](contactmomenten.md) |
| Contactmomenten Rapportage | 98% of klantinteractie tenders | [contactmomenten-rapportage.md](contactmomenten-rapportage.md) |
| Terugbel- & Taakbeheer | 31% of klantinteractie tenders | [terugbel-taakbeheer.md](terugbel-taakbeheer.md) |
| Queue Management | Government CRM/KCC tooling | [queue-management.md](queue-management.md) |
| Klachtenregistratie | 141 tenders, 637 requirements | [klachtenregistratie.md](klachtenregistratie.md) |
| Kennisbank | 51/52 KCC tenders | [kennisbank.md](kennisbank.md) |
| Klantbeeld 360 | KCC citizen profile | [klantbeeld-360.md](klantbeeld-360.md) |
| KCC Werkplek | Municipal KCC | [kcc-werkplek.md](kcc-werkplek.md) |
| Omnichannel Registration | Channel-aware logging | [omnichannel-registratie.md](omnichannel-registratie.md) |

### Analytics & Insights (V1)

Reporting and intelligence features for CRM managers and team leads.

| Feature | Docs |
|---------|------|
| Pipeline Insights | [pipeline-insights.md](pipeline-insights.md) |
| Customer Satisfaction (KTO) | [customer-satisfaction.md](customer-satisfaction.md) |
| Contact Relationship Mapping | [contact-relationship-mapping.md](contact-relationship-mapping.md) |

### Enterprise / Automation

Advanced features for larger deployments.

| Feature | Docs |
|---------|------|
| CRM Workflow Automation | [crm-workflow-automation.md](crm-workflow-automation.md) |
| Email & Calendar Sync | [email-calendar-sync.md](email-calendar-sync.md) |
| Public Intake Forms | [public-intake-forms.md](public-intake-forms.md) |
| Product Catalog Quoting | [product-catalog-quoting.md](product-catalog-quoting.md) |

### Infrastructure

| Feature | Docs |
|---------|------|
| OpenRegister Integration | [openregister-integration.md](openregister-integration.md) |
| Admin Settings | [admin-settings.md](admin-settings.md) |
| Register i18n | [register-i18n.md](register-i18n.md) |
| Prometheus Metrics | [prometheus-metrics.md](prometheus-metrics.md) |

## Spec-to-Feature Mapping

Used by `/opsx:archive` to determine the correct feature doc when archiving a change.

```
client-management       → client-management.md
contacts-sync           → contacts-sync.md
lead-management         → lead-management.md
lead-product-link       → lead-product-link.md
prospect-discovery      → prospect-discovery.md
request-management      → request-management.md
pipeline                → pipeline-kanban.md
pipeline-insights       → pipeline-insights.md
dashboard               → dashboard.md
my-work                 → my-work.md
entity-notes            → entity-notes.md
notifications-activity  → notifications-activity.md
activity-timeline       → activity-timeline.md
product-catalog         → product-service-catalog.md
product-service-catalog → product-service-catalog.md
product-catalog-quoting → product-catalog-quoting.md
contact-relationship-mapping → contact-relationship-mapping.md
omnichannel-registratie → omnichannel-registratie.md
contactmomenten         → contactmomenten.md
contactmomenten-rapportage → contactmomenten-rapportage.md
klantbeeld-360          → klantbeeld-360.md
kennisbank              → kennisbank.md
knowledge-base          → kennisbank.md
terugbel-taakbeheer     → terugbel-taakbeheer.md
callback-management     → terugbel-taakbeheer.md
queue-management        → queue-management.md
skill-routing           → queue-management.md
klachtenregistratie     → klachtenregistratie.md
customer-satisfaction   → customer-satisfaction.md
kcc-werkplek            → kcc-werkplek.md
crm-workflow-automation → crm-workflow-automation.md
email-calendar-sync     → email-calendar-sync.md
public-intake-forms     → public-intake-forms.md
register-i18n           → register-i18n.md
openregister-integration → openregister-integration.md
admin-settings          → admin-settings.md
prometheus-metrics      → prometheus-metrics.md
```
