# Contactmomenten

**Status:** Planned

## Overview

Core CRUD and lifecycle management for contactmoment records — structured logs of every interaction between an agent and a citizen or client (phone, email, counter, chat). Without this, Pipelinq cannot serve as the klantinteractie hub required by 54% of Dutch government tenders.

Contactmomenten are distinct from the Contactmomenten Rapportage feature: this spec covers the core entity, views, and storage; reporting is covered separately in [contactmomenten-rapportage.md](contactmomenten-rapportage.md).

## Standards

- **GEMMA Callcentercomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-9d127615-3b66-4d9e-9071-2a85f9cd44d8)
- **VNG Klantinteracties**: `Contactmoment` object
- **Schema.org**: `CommunicateAction`
- **TEC CRM**: Section 3.1 (Creating New Cases / Service Requests)

## Market Demand

- **54%** of Dutch klantinteractie government tenders require structured contactmoment registration
- Backed by validated market intelligence across 39K+ tenders

## Key Capabilities

- Contactmoment entity with fields: timestamp, agent, client reference, channel, subject, summary, outcome, duration, linked request/case, channel-specific metadata
- List view (`/contactmomenten`) with search, sort, filter by channel/agent/date range, pagination
- Detail view: full record, linked client, linked request/case, channel metadata
- Quick-log form: accessible from client detail, request detail, and the contactmomenten list; pre-fills context (client, request) when launched from those views
- Client timeline integration: contactmomenten appear in the client detail activity timeline sorted chronologically with other activities
- Pinia store (`contactmomentenStore`) querying OpenRegister API for CRUD

## Data Model

Fields aligned to VNG Klantinteracties `Contactmoment` and Schema.org `CommunicateAction`:

| Field | Type | Description |
|-------|------|-------------|
| timestamp | datetime | When the interaction occurred |
| agent | user ref | Nextcloud user who handled the interaction |
| client | object ref | Linked client (person or organization) |
| channel | enum | phone, email, counter, chat, web, other |
| subject | string | Short subject/topic |
| summary | text | Full interaction summary |
| outcome | enum | resolved, follow-up, transferred, voicemail |
| duration | integer | Duration in seconds |
| linkedRequest | object ref | Optional linked request/verzoek |
| linkedCase | string | Optional external case reference |

## Impact

- **Frontend**: New views (`src/views/contactmomenten/`), new store (`src/store/contactmomenten.js`), new route entries, navigation item
- **Register schema**: `lib/Settings/pipelinq_register.json` gains Contactmoment object definition
- **Existing views**: Client detail and request detail views get additional sections/tabs for linked contactmomenten
- **Procest bridge**: Contactmomenten linked to requests carry over when a request is converted to a case in Procest

## Specification

Full specification: `openspec/changes/archive/2026-03-22-contactmomenten/specs/`

Related changes:
- Design: `openspec/changes/archive/2026-03-22-contactmomenten/design.md`
- Tasks: `openspec/changes/archive/2026-03-22-contactmomenten/tasks.md`
