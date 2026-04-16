# Proposal: entity-notes

## Problem

Pipelinq entity detail pages (clients, contacts, leads, requests) display structured data but have no integrated view of communication history or internal notes. Agents opening a client page before handling a call cannot see previous interactions at a glance — they must navigate to the separate contactmomenten list and manually filter. There is also no REST API endpoint for querying activity instances (notes + contactmomenten) per entity, blocking integration with external systems.

308 tenders explicitly require omnichannel customer communication management as a core CRM capability. 25 tenders require programmatic access to activity instances via REST API.

## Solution

Integrate communication history and notes directly into entity detail views using platform capabilities:

1. **Entity Notes** — Enable OpenRegister's built-in notes field for all Pipelinq entity types by wiring `CnObjectSidebar` with the `notesPlugin` for clients, contacts, leads, and requests. Agents can create, view, and delete internal notes without leaving the entity page.

2. **Communication History panel** — Add an inline `CommunicationHistory.vue` section to client, contact, lead, and request detail pages. The panel displays linked `contactmoment` objects (fetched via OpenRegister's `relationsPlugin`) in reverse chronological order with channel icon, subject, agent, and timestamp. Each entry links to the full contactmoment detail.

3. **Activity REST API** — Expose a dedicated `/api/activity/{entityType}/{entityId}` endpoint that aggregates notes and contactmomenten for any entity. Supports pagination (`_page`, `_limit`) and type filtering (`?type=notes|contactmomenten|all`). Required for third-party integrations and reporting.

### Why CnObjectSidebar over custom notes storage

- OpenRegister's built-in `notes` field is already managed — no schema changes, no data model additions
- `CnObjectSidebar` handles notes display, creation, and deletion out of the box
- The `relationsPlugin` fetches linked contactmomenten without custom query logic
- Custom storage would duplicate functionality the platform already provides

## Scope

- Notes via `CnObjectSidebar` (Notes tab) on all Pipelinq entity detail pages
- `CommunicationHistory.vue` inline panel on client, contact, lead, request detail pages
- Activity REST API with pagination and type filter
- Seed data: 5 realistic contactmoment objects (Dutch values) linked to existing client seed data

## Out of scope

- @ mentions or user tagging in notes (V1)
- Real-time collaborative editing of notes (V1)
- Bulk note operations (V1)
- Push notifications on new notes (V1)
- File attachments on individual notes (handled by Files tab in CnObjectSidebar)
