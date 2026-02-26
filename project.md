# Pipelinq — CRM for Nextcloud

## Overview

Pipelinq is a lightweight Client Relationship Management (CRM) app for Nextcloud, built as a thin client on top of OpenRegister. It manages clients, contacts, leads, pipelines, requests (verzoeken), contact moments, and messages — the customer-facing side of case management.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (all data stored as register objects)
- **Pattern**: Thin client — Pipelinq provides UI/UX, OpenRegister handles persistence
- **License**: AGPL-3.0-or-later

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed architecture and data model decisions.

## Standards

**Principle: international standards for data storage, Dutch standards as API mapping layer.**

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Primary** | Schema.org + vCard (RFC 6350) | International data model |
| **Semantic** | Schema.org JSON-LD | Linked data interoperability |
| **API mapping** | VNG Klantinteracties + Verzoeken | Dutch government compatibility |
| **Pattern** | Industry CRM consensus | Proven UX patterns |
| **Nextcloud** | Contacts, Calendar, Users | Native reuse |

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1+, Nextcloud App Framework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Data | OpenRegister (JSON object storage) |
| Build | Webpack 5, @nextcloud/webpack-vue-config |
| i18n | English, Dutch |

## Features

### Implemented (MVP)

| Feature | Description | Status |
|---------|-------------|--------|
| Admin Settings | Register/schema configuration, app settings | Done |
| Client Management | CRUD for clients (persons/organizations) with detail views | Done |
| Lead Management | Lead tracking with pipeline stages and kanban view | Done |
| Pipeline & Stages | Configurable pipelines with drag-and-drop stage management | Done |
| Request Management | Intake/inquiry handling before cases (verzoeken) | Done |
| Dashboard | KPI cards, pipeline chart, recent activity, my work preview | Done |
| My Work | Personal work queue with assigned leads and requests | Done |
| Unified Search Deep Links | Clients, leads, requests, contacts appear in Nextcloud search with links to Pipelinq detail views | Done |

### Planned

Features derived from zaakafhandelapp analysis and feature counsel.

| Feature | Description | Priority | Source |
|---------|-------------|----------|--------|
| Contact Moments | Log interactions with clients (calls, emails, visits) — ZGW: Contactmomenten | MUST | ZAA Feature |
| Messages (Berichten) | Customer correspondence and communication tracking | MUST | ZAA Feature |
| Snelle Start Sidebar | Quick-start sidebar with tabs: instructions, your clients, your tasks | SHOULD | ZAA Dashboard |
| Contract/Offerte Management | Contract entity: title, client, dates, value, status, linked lead | SHOULD | Feature Counsel |
| CSV/Excel Import/Export | Import existing client data, export for reporting | MUST | Feature Counsel |
| Bulk Operations | Bulk select, assign, delete, export on all list views | MUST | Feature Counsel |
| VNG Klantinteracties API | VNG-compatible Klantinteracties endpoints | SHOULD | Feature Counsel |
| VNG Verzoeken API | VNG-compatible Verzoeken endpoints | SHOULD | Feature Counsel |
| Notes | First-class notes per client/lead/request | SHOULD | Feature Counsel |
| Tags/Labels | Multi-tag filtering for customer segmentation | SHOULD | Feature Counsel |
| Email Integration | Link to Nextcloud Mail for correspondence tracking | COULD | Feature Counsel |

### Shared with OpenRegister

These features are implemented at the OpenRegister level, benefiting all consumer apps:

| Feature | Description |
|---------|-------------|
| Nextcloud Unified Search | Search provider with deep link registry (apps register URL patterns per schema) |
| Audit Trail | Comprehensive audit logging with export capability |
| Business Rules Engine | Server-side validation, status transitions, event hooks |

### Boundary with Procest

Pipelinq focuses on the **customer-facing/CRM side** (who, communication, intake). Procest handles **internal case processing** (what happens after intake).

| Concern | Pipelinq | Procest |
|---------|----------|---------|
| Clients (Klanten) | Owns | References |
| Contact Moments | Owns | — |
| Messages (Berichten) | Owns | — |
| Leads & Pipelines | Owns | — |
| Requests (Verzoeken) | Owns (intake) | Receives (as cases) |
| Cases (Zaken) | Links to (context) | Owns |
| Tasks (Taken) | — | Owns |
| Roles (Rollen) | — | Owns |
| Employees (Medewerkers) | Via Nextcloud Users | Via Nextcloud Users |
| Search | Via OpenRegister unified search | Via OpenRegister unified search |

### Data Model (Extended)

| Object | Description | Schema.org Type | VNG Mapping |
|--------|-------------|----------------|-------------|
| Client | Person or organization | `Person` / `Organization` | Partij |
| Contact | Contact person linked to a client | `Person` + `worksFor` | Contactpersoon |
| Request | Intake/inquiry before it becomes a case | `Demand` | Verzoek |
| Contact Moment | Record of interaction with a client | `CommunicateAction` | Contactmoment |
| Message | Correspondence sent to/from a client | `Message` | Bericht |
| Lead | Sales opportunity linked to a client | `Offer` | — |
| Pipeline | Configurable workflow stages for leads | `ItemList` | — |
| Contract | Agreement with a client (future) | `Order` | — |

## Key Directories

```
pipelinq/
├── appinfo/          # App manifest and routes
├── lib/              # PHP backend (controllers, services, repair)
├── src/              # Vue frontend source
├── docs/             # Architecture and documentation
├── openspec/         # OpenSpec specs and changes
├── l10n/             # Translations
└── templates/        # PHP templates
```

## Development

- **Local URL**: http://localhost:8080/apps/pipelinq/
- **Requires**: OpenRegister app installed and enabled
- **Docker**: Part of openregister/docker-compose.yml
