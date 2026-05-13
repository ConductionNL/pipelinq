# Pipelinq — Architecture & Data Model

## 1. Overview

Pipelinq is a CRM (Client Relationship Management) app for Nextcloud, built as a thin client on OpenRegister. It manages clients (persons and organizations), contact persons, leads (sales opportunities), and requests (service intake — the pre-state of a case). Both leads and requests can flow through configurable pipelines with kanban-style boards.

### Architecture Pattern

```
┌─────────────────────────────────────────────────┐
│  Pipelinq Frontend (Vue 2 + Pinia)              │
│  - Client list/detail views                     │
│  - Contact person views                         │
│  - Lead views + pipeline kanban                 │
│  - Request (verzoek) views + pipeline kanban    │
│  - My Work (werkvoorraad) dashboard             │
│  - Admin settings                               │
└──────────────┬──────────────────────────────────┘
               │ REST API calls
┌──────────────▼──────────────────────────────────┐
│  OpenRegister API                                │
│  /api/objects/{register}/{schema}/{id}           │
│  - CRUD operations                              │
│  - Search, pagination, filtering                │
└──────────────┬──────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────┐
│  OpenRegister Storage (PostgreSQL)               │
│  - JSON object storage                          │
│  - Schema validation                            │
└─────────────────────────────────────────────────┘
```

Pipelinq owns **no database tables**. All data is stored as OpenRegister objects, defined by schemas in a dedicated register.

## 2. Standards Research

Before defining our data model, we evaluated multiple standards across three categories.

### 2.1 Standards Evaluated

| Standard | Type | Coverage | Maturity | Relevance |
|----------|------|----------|----------|-----------|
| **VNG Klantinteracties API** | Dutch gov | Partij, Klantcontact, Betrokkene, InterneTaak, DigitaalAdres | Pre-1.0 (half-product) | **HIGH** — exact domain match |
| **VNG Verzoeken API** | Dutch gov | Verzoek, KlantVerzoek, VerzoekProduct | Part of ZGW family | **HIGH** — models pre-case intake |
| **Schema.org** | International | Person, Organization, ContactPoint, Demand, Role, ItemList | Very mature | **HIGH** — primary vocabulary |
| **vCard / jCard (RFC 6350/7095)** | International | Contact data fields | Very mature | **MEDIUM** — field reference for contacts |
| **OASIS CIQ v3.0** | International | Names, addresses, party relationships | Mature (XML-based) | **LOW** — dated format |
| **Industry CRM consensus** | Industry | Account, Contact, Lead/Deal, Pipeline, Stage | De facto standard | **HIGH** — proven patterns |
| **W3C Organization Ontology** | International | Organizational structure, membership, roles | W3C Recommendation | **LOW** — too abstract |

### 2.2 Design Principle: International First

> **Data storage uses international standards. Dutch government standards are an API mapping layer.**

This means:
- Objects in OpenRegister are modeled after **schema.org and vCard** conventions
- When exposing a VNG-compatible API, we **map** our international objects to Klantinteracties/Verzoeken field names
- This makes Pipelinq usable outside the Netherlands while remaining interoperable with Dutch systems

### 2.3 Key Findings

1. **Schema.org** provides the primary vocabulary: `Person`, `Organization`, `ContactPoint`, `Demand`, `Role`, `ItemList`, `DefinedTerm`. Every entity carries a `schema:` type annotation for linked data compatibility.

2. **vCard (RFC 6350)** represents decades of real-world contact management. We use vCard property conventions as the field reference for contact data (but in flat JSON, not jCard array format).

3. **Industry CRM models** (Salesforce, HubSpot, EspoCRM, Twenty) provide proven patterns for pipeline/kanban management. All major CRMs store the current stage directly on the entity (not in a junction table). HubSpot and Twenty prove that a unified Lead entity (without a separate Opportunity split) works at scale.

4. **VNG Klantinteracties** is the Dutch government standard for this domain (Partij, Klantcontact, Betrokkene). It is immature (pre-1.0, deprioritized since mid-2024) but we map to it for Dutch API compatibility.

5. **VNG Verzoeken API** defines the "verzoek" (request) as the pre-case intake. We map to it for the verzoek-to-zaak flow connecting Pipelinq to Procest.

6. **Nextcloud** provides built-in Contacts (CardDAV/vCard), Calendar (CalDAV), and user management that we reuse where possible.

## 3. Data Model Decisions

### 3.1 Chosen Standards

We adopt a **layered standards approach**:

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Primary (storage)** | Schema.org + vCard (RFC 6350) | International data model |
| **Semantic** | Schema.org JSON-LD | Type annotations for linked data |
| **API mapping** | VNG Klantinteracties + Verzoeken | Dutch government interoperability |
| **Pattern** | Industry CRM consensus | Proven UX patterns (pipeline, stages, kanban) |
| **Nextcloud native** | Contacts, Calendar, Users | Reuse where possible |

### 3.2 Entity Definitions

#### Client (Klant/Partij)

A client can be either a person or an organization.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Person` or `schema:Organization` | Primary international standard |
| **vCard alignment** | `FN`, `EMAIL`, `TEL`, `ADR`, `URL` | Field naming from RFC 6350 |
| **VNG mapping** | `Partij` (soort: Persoon \| Organisatie) | Dutch API compatibility |
| **Dual-nature** | Support both person and organization as client types | Required by schema.org; matches industry CRM Account/Contact split |
| **Nextcloud reuse** | Investigate Contacts app (CardDAV) | May reuse native vCard contacts |

**Core properties** (schema.org primary, VNG mapping):

| Property | Type | Schema.org | vCard (RFC 6350) | VNG Mapping | Required |
|----------|------|------------|------------------|-------------|----------|
| `name` | string | `schema:name` | `FN` | Partij.naam | Yes |
| `type` | enum: person, organization | `@type` | `KIND` | soortPartij | Yes |
| `email` | string | `schema:email` | `EMAIL` | DigitaalAdres | No |
| `telephone` | string | `schema:telephone` | `TEL` | DigitaalAdres | No |
| `address` | object | `schema:address` | `ADR` | bezoekadres | No |
| `taxID` | string | `schema:taxID` | — | partijIdentificator | No (orgs) |
| `website` | string | `schema:url` | `URL` | DigitaalAdres | No |
| `notes` | string | `schema:description` | `NOTE` | — | No |

#### Contact (Contactpersoon)

A contact person linked to a client organization.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Person` with `schema:worksFor` → Client | International standard |
| **vCard alignment** | Contact as vCard with `RELATED` to organization | RFC 6350 relationship |
| **VNG mapping** | `Partij` (soort: Contactpersoon) linked via `Betrokkene` | Dutch API compatibility |
| **Role qualification** | Use `schema:Role` | Schema.org role-qualified relationships |

**Core properties**:

| Property | Type | Schema.org | vCard | VNG Mapping | Required |
|----------|------|------------|-------|-------------|----------|
| `name` | string | `schema:name` | `FN` | Partij.naam | Yes |
| `email` | string | `schema:email` | `EMAIL` | DigitaalAdres | No |
| `telephone` | string | `schema:telephone` | `TEL` | DigitaalAdres | No |
| `role` | string | `schema:roleName` | `ROLE` | Betrokkene.rol | No |
| `client` | reference | `schema:worksFor` | `RELATED` | Betrokkene → Partij | Yes |
| `jobTitle` | string | `schema:jobTitle` | `TITLE` | — | No |

#### Lead

A lead represents a sales opportunity — from first contact through to won or lost. Leads flow through configurable pipeline stages. Unlike Salesforce's Lead→Opportunity split, we use a unified entity (proven by HubSpot and Twenty) where pipeline stages encode qualification level.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Demand` | "Announcement to seek a certain type of goods or services" — matches lead concept |
| **Industry pattern** | Unified Lead (no Opportunity split) | HubSpot/Twenty prove single entity works; stages encode qualification |
| **Pipeline** | Stage stored on entity (`lead.stage`) | Industry consensus (Salesforce, HubSpot, EspoCRM) — simpler queries, atomic updates |

**Core properties**:

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `title` | string | `schema:name` | Yes | — |
| `description` | string | `schema:description` | No | — |
| `client` | reference | `schema:customer` | No | — |
| `contact` | reference | `schema:buyer` | No | — |
| `source` | enum | — | No | — |
| `value` | number | `schema:price` | No | — |
| `currency` | string (ISO 4217) | `schema:priceCurrency` | No | EUR |
| `probability` | integer (0–100) | — | No | — |
| `expectedCloseDate` | date | `schema:validThrough` | No | — |
| `pipeline` | reference | — | No | — |
| `stage` | reference | — | No | — |
| `stageOrder` | integer | — | No | 0 |
| `assignedTo` | string (user UID) | `schema:agent` | No | — |
| `priority` | enum: low, normal, high, urgent | — | No | normal |
| `category` | string | `schema:category` | No | — |

**Lead source values** (industry consensus from Salesforce/EspoCRM):

| Source | Description |
|--------|-------------|
| `website` | Inbound from website |
| `email` | Email inquiry |
| `phone` | Phone call |
| `referral` | Referred by existing client |
| `partner` | Partner introduction |
| `campaign` | Marketing campaign |
| `social_media` | Social media |
| `event` | Event/conference |
| `other` | Other source |

#### Request (Verzoek)

A request is a service intake/inquiry before something becomes a case. This is the bridge between Pipelinq (CRM) and Procest (case management). Requests can optionally flow through a pipeline alongside leads.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Demand` | "Announcement to seek a certain type of goods or services" — closest match |
| **VNG mapping** | `Verzoek` from Verzoeken API | Dutch API compatibility |
| **Lifecycle** | new → in_progress → completed / rejected / converted | International, maps to VNG lifecycle |
| **Pipeline** | Optional — requests can be placed on a pipeline | Enables mixed kanban boards with leads and requests |
| **Case link** | Request can convert to a Procest case | Standard request-to-case flow |

**Core properties**:

| Property | Type | Schema.org | VNG Mapping | Required |
|----------|------|------------|-------------|----------|
| `title` | string | `schema:name` | Verzoek.tekst | Yes |
| `description` | string | `schema:description` | Verzoek.tekst | No |
| `client` | reference | `schema:customer` | KlantVerzoek → Klant | No |
| `status` | enum | `schema:actionStatus` | Verzoek.status | Yes (default: new) |
| `priority` | enum: low, normal, high, urgent | — | — | No (default: normal) |
| `category` | string | `schema:category` | VerzoekProduct | No |
| `requestedAt` | datetime | `schema:dateCreated` | registratiedatum | Auto |
| `channel` | string | `schema:availableChannel` | — | No |
| `pipeline` | reference | — | — | No |
| `stage` | reference | — | — | No |
| `stageOrder` | integer | — | — | No (default: 0) |
| `assignedTo` | string (user UID) | `schema:agent` | — | No |

#### Pipeline

A pipeline is a configurable kanban board with ordered stages. Multiple entity types (leads and requests) can appear as cards on the same pipeline — the frontend merges them for a combined view.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:ItemList` | Ordered list of items (stages) |
| **Industry pattern** | Trello Board / HubSpot Pipeline / Deck Board | Three-level: Pipeline → Stages → Cards |
| **Polymorphic cards** | Shared Pipeline/Stage entities, referenced from both Lead and Request | No junction table — frontend merges two API calls. Industry-standard pattern. |

**Core properties**:

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `title` | string | `schema:name` | Yes | — |
| `description` | string | `schema:description` | No | — |
| `entityTypes` | string[] | — | Yes | ["lead"] |
| `isDefault` | boolean | — | No | false |
| `color` | string (hex) | — | No | — |

#### Stage

A stage is a column within a pipeline. Stages have an explicit order and optional probability (for sales forecasting).

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:DefinedTerm` | Term within a controlled vocabulary |
| **Industry pattern** | Trello List / HubSpot Stage / Deck Stack | Column in a kanban board |
| **Probability mapping** | Stage probability auto-populates lead probability on stage change | Proven by EspoCRM's stage→probability mapping |

**Core properties**:

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `title` | string | `schema:name` | Yes | — |
| `description` | string | `schema:description` | No | — |
| `pipeline` | reference | `schema:inDefinedTermSet` | Yes | — |
| `order` | integer | `schema:position` | Yes | 0 |
| `color` | string (hex) | — | No | — |
| `probability` | integer (0–100) | — | No | — |
| `isClosed` | boolean | — | No | false |
| `isWon` | boolean | — | No | false |

**Default Sales Pipeline** (created during app initialization):

| Order | Stage | Probability | isClosed | isWon |
|-------|-------|-------------|----------|-------|
| 0 | New | 10 | false | false |
| 1 | Contacted | 20 | false | false |
| 2 | Qualified | 40 | false | false |
| 3 | Proposal | 60 | false | false |
| 4 | Negotiation | 80 | false | false |
| 5 | Won | 100 | true | true |
| 6 | Lost | 0 | true | false |

**Default Service Requests Pipeline**:

| Order | Stage | Probability | isClosed | isWon |
|-------|-------|-------------|----------|-------|
| 0 | New | — | false | false |
| 1 | In Progress | — | false | false |
| 2 | Completed | — | true | true |
| 3 | Rejected | — | true | false |
| 4 | Converted to Case | — | true | false |

### 3.3 Status Values

**Request statuses** (aligned with VNG Verzoeken lifecycle):

| Status | Dutch | Description |
|--------|-------|-------------|
| `new` | Nieuw | Just received, not yet triaged |
| `in_progress` | In behandeling | Being processed |
| `completed` | Afgehandeld | Successfully completed |
| `rejected` | Afgewezen | Rejected/declined |
| `converted` | Omgezet naar zaak | Converted to a case in Procest |

### 3.4 My Work (Werkvoorraad)

A cross-entity workload view showing all items assigned to the current user. No new entity is needed — this is a frontend aggregation pattern.

**How it works**:
- Query leads with `assignedTo == currentUser` and open stages
- Query requests with `assignedTo == currentUser` and open statuses
- Optionally include tasks from Procest (`assignee == currentUser`)
- Merge, sort by priority then due date, display as unified card list

**Required fields for My Work** (already present on Lead, Request, and Procest Task):

| Field | Lead | Request | Procest Task |
|-------|------|---------|-------------|
| `assignedTo` / `assignee` | Yes | Yes | Yes |
| `priority` | Yes | Yes | Yes |
| `dueDate` / `expectedCloseDate` | Yes | — | Yes |
| `status` / `stage` | Yes | Yes | Yes |
| Entity type label | "Lead" | "Request" | "Task" |

### 3.5 Relationship to Procest

Pipelinq and Procest share the **request-to-case** (verzoek-to-zaak) flow from Dutch government standards:

```
Pipelinq (CRM)                    Procest (Case Management)
┌──────────────┐                  ┌──────────────┐
│   Client     │                  │              │
│   Contact    │──── Request ────>│    Case      │
│   Lead       │   (verzoek)     │    Task      │
│   Pipeline   │                  │    Decision   │
└──────────────┘                  └──────────────┘
```

A Request in Pipelinq can be converted to a Case in Procest. The `client` reference is preserved so the case knows which client initiated the request.

### 3.6 Admin Settings

Pipelinq exposes a **Nextcloud admin settings panel** for app configuration. Settings are stored in OpenRegister as configuration objects and/or via Nextcloud's `IAppConfig`.

**Configurable by admin**:

| Setting | Type | Description |
|---------|------|-------------|
| Pipeline management | CRUD | Create, edit, delete pipelines and their stages |
| Stage management | CRUD | Create, edit, reorder, delete stages per pipeline |
| Default pipeline | Selection | Which pipeline is used by default for new leads/requests |
| Lead sources | List | Configurable lead source values |
| Request channels | List | Configurable request channel values |
| Priority levels | Display | Customize priority labels and colors |

### 3.7 Nextcloud Integration Strategy

**Principle: reuse Nextcloud native objects where possible, reference by ID, don't duplicate.**

OpenRegister objects store CRM-specific fields plus **foreign keys** (vCard UID, calendar event UID, file ID, user UID) pointing to Nextcloud native entities. The PHP service layer uses OCP interfaces to read/write native data.

#### REUSE from Nextcloud

| Feature | OCP Interface | What to Reuse | How |
|---------|--------------|---------------|-----|
| **Contacts** | `OCP\Contacts\IManager` | Person/org master data (name, email, phone, address) | Reference by vCard UID. Search via `IManager::search()`. Create/update via `IManager::createOrUpdate()`. |
| **Calendar** | `OCP\Calendar\IManager` | Follow-up dates, deadlines, appointments | Create events via `ICalendarEventBuilder` (NC 31+). Reference by event UID. Expose CRM deadlines as virtual calendar via `ICalendarProvider`. |
| **Users** | `OCP\IUserManager` | Authentication identity, assignees, handlers | Reference by user UID. Use `IAccountManager` for profile fields (org, role, phone). |
| **Files** | `OCP\Files\IRootFolder` | Document attachments on clients/requests | Reference by Nextcloud file ID. Resolve via `IRootFolder->getById()`. |
| **Activity** | `OCP\Activity\IManager` | Unified interaction timeline | Publish CRM events ("Request created", "Client updated") to activity stream. Implement `IProvider` for rendering. |
| **Talk** | `OCP\Talk\IBroker` | Real-time conversations per client/request | Create conversation via `IBroker::createConversation()`. Store token in OpenRegister object. |
| **Comments** | `OCP\Comments\ICommentsManager` | Notes/comments on any CRM object | Attach comments using objectType + objectId. Supports threads, mentions, reactions. |
| **System Tags** | `OCP\SystemTag\ISystemTagObjectMapper` | Cross-reference and categorize objects | Tag files and objects with CRM categories. |

#### BUILD in OpenRegister (CRM-specific)

| What | Why Not Reuse |
|------|---------------|
| **Client metadata** | CRM-specific: type (person/org), status, source, account manager, linked requests |
| **Contact relationships** | Role-qualified links between contacts and client organizations |
| **Leads** | Sales-specific: value, probability, expected close date, pipeline stage |
| **Requests (verzoeken)** | Domain-specific lifecycle, pipeline stages, priority, category |
| **Pipelines & stages** | CRM-specific configurable workflow boards |
| **Interaction logs** | Structured records (call log, email summary, meeting notes) with type, date, duration, outcome |

#### Key OCP Interfaces

```php
// Contacts - search and create
$contactsManager = \OCP\Server::get(\OCP\Contacts\IManager::class);
$results = $contactsManager->search('John', ['FN', 'EMAIL'], ['limit' => 10]);
$contactsManager->createOrUpdate($properties, $addressBookKey);

// Calendar - create events
$calendarManager = \OCP\Server::get(\OCP\Calendar\IManager::class);
$builder = $calendarManager->createEventBuilder(); // NC 31+
$builder->setSummary('Follow-up: Request #123')->setStartDate($date);

// Activity - publish CRM events
$activityManager = \OCP\Server::get(\OCP\Activity\IManager::class);
$event = $activityManager->generateEvent();
$event->setApp('pipelinq')->setType('request_update')->setSubject('...');
$activityManager->publish($event);

// Files - resolve attachments
$rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$files = $rootFolder->getById($fileId);

// Talk - create per-client conversation
$broker = \OCP\Server::get(\OCP\Talk\IBroker::class);
$conversation = $broker->createConversation('Client: Acme Corp', [$userId]);
```

## 4. OpenRegister Configuration

### Register

| Field | Value |
|-------|-------|
| Name | `pipelinq` |
| Slug | `pipelinq` |
| Description | Client relationship management register |

### Schema Definitions

Schemas MUST be defined in `lib/Settings/pipelinq_register.json` using OpenAPI 3.0.0 format (not inline PHP), following the pattern used by opencatalogi and softwarecatalog.

**Schemas**:
- `client` — Person or organization (schema:Person / schema:Organization)
- `contact` — Contact person linked to client (schema:Person + worksFor)
- `lead` — Sales opportunity (schema:Demand)
- `request` — Service intake/inquiry (schema:Demand)
- `pipeline` — Kanban board configuration (schema:ItemList)
- `stage` — Pipeline column (schema:DefinedTerm)

The configuration is imported via `ConfigurationService::importFromApp()` in the repair step.

## 5. Open Research Questions

The following questions need further investigation as the app matures:

1. **VNG Klantinteracties stability** — The API is pre-1.0 and deprioritized. Should we track its evolution or diverge? Current decision: align conceptually, don't depend on API stability.

2. **DigitaalAdres as separate entity** — VNG models digital addresses (email, phone) as separate objects linked to a Partij. Should Pipelinq follow this pattern or keep contact fields inline on Client/Contact? Current decision: inline for simplicity, refactor if interoperability requires it.

3. ~~**Pipeline/stages for requests**~~ — **RESOLVED**: Configurable pipelines with stages are now a core feature. Both leads and requests can flow through pipelines. Stages are stored directly on entities (industry consensus).

4. **BSN/KVK integration** — VNG Partij supports `partijIdentificator` for BSN (citizens) and KVK numbers (organizations). When should Pipelinq support government ID lookups?

5. **Multi-channel support** — VNG Klantinteracties models omnichannel interactions (mail, phone, web, counter). Should Pipelinq track which channel a request came from? Current decision: `channel` field on Request, configurable values in admin settings.

6. **Lead-to-order flow** — Leads can be won, but order/product/finance management is out of scope for now. When should Pipelinq support post-sale workflows?

## 6. References

### Primary Standards (International)
- [Schema.org](https://schema.org/) — Linked data vocabulary (primary data model)
- [RFC 6350 — vCard](https://www.rfc-editor.org/rfc/rfc6350.html) — Contact data field reference
- [W3C Organization Ontology](https://www.w3.org/TR/vocab-org/) — Organizational relationships

### Schema.org Types Used
- [schema:Person](https://schema.org/Person) — Individual client or contact
- [schema:Organization](https://schema.org/Organization) — Organization client
- [schema:ContactPoint](https://schema.org/ContactPoint) — Contact channel
- [schema:Demand](https://schema.org/Demand) — Lead and Request
- [schema:Role](https://schema.org/Role) — Relationship qualification
- [schema:ItemList](https://schema.org/ItemList) — Pipeline (ordered list)
- [schema:DefinedTerm](https://schema.org/DefinedTerm) — Stage (controlled vocabulary term)
- [schema:customer](https://schema.org/customer) — Customer property
- [schema:agent](https://schema.org/agent) — Assigned user

### Dutch Standards (API Mapping Layer)
- [VNG Klantinteracties API](https://vng-realisatie.github.io/klantinteracties/) — Dutch government customer interaction standard
- [VNG Verzoeken API](https://vng-realisatie.github.io/gemma-zaken/standaard/verzoeken/index) — Dutch government request/intake standard
- [GEMMA Online](https://www.gemmaonline.nl/) — Dutch municipal architecture

### Industry References
- [Salesforce Data Model](https://developer.salesforce.com/docs/atlas.en-us.object_reference.meta/object_reference/data_model.htm)
- [HubSpot CRM API](https://developers.hubspot.com/docs/guides/crm/understanding-the-crm) — Pipeline/stage model reference
- [EspoCRM Entity Definitions](https://github.com/espocrm/espocrm/tree/master/application/Espo/Modules/Crm/Resources/metadata/entityDefs) — Lead/Opportunity model reference
- [Nextcloud Deck API](https://github.com/nextcloud/deck/blob/main/docs/API.md) — Board/Stack/Card pattern reference
