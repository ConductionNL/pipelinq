# Klachtenregistratie

**Status:** Planned

## Overview

Complaint registration, categorization, and follow-up tracking for Pipelinq. Complaints are linked to clients, organizations, and optionally to cases (Procest), with a full audit trail and SLA-based deadline tracking.

## Standards

- **GEMMA Klachten- en meldingencomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-d2d0679e-1fe3-4ec3-9b56-e11d693d1408)
- **TEC CRM**: Sections 3.1–3.4 (Case creation, assignment, escalation, closure)
- **VNG Verzoeken API**: Klacht as a specialized verzoek type

## Market Demand

- **141 tenders** across the "Klachtenregistratie" cluster
- **637 requirements** extracted from public procurement documents
- Predominantly Dutch municipal and government tenders

Representative requirement: *"Opdrachtnemer dient te beschikken over een adequaat klachtenregistratiesysteem. Bij Opdrachtnemer gemelde klachten dienen zo spoedig mogelijk, doch uiterlijk binnen 1 werkdag na melding, door Opdrachtnemer in behandeling te worden genomen."*

## Key Capabilities

### Complaint CRUD
- Register new complaints linked to a client or organization
- Fields: subject, description, category, channel (received via), linked client, linked case reference, received date, deadline
- Complaint list view with search, filter by status/category/deadline, sort
- Complaint detail view with full history and audit trail

### Complaint Lifecycle
Status flow: `nieuw` → `in_behandeling` → `opgelost` → `gesloten` / `heropend`

- Status transitions are logged with timestamp and agent
- Re-open workflow with mandatory reason

### Categorization
- Complaint category taxonomy (configurable): product quality, service delivery, communication, billing, other
- Category reporting for trend analysis

### SLA Tracking
- Configurable response and resolution deadlines per category
- Visual deadline indicators: on-track (green), at-risk (amber), overdue (red)
- Escalation notifications when deadlines approach or are breached

### Audit Trail
- Every status change, note, and assignment is logged immutably
- Full history visible on complaint detail view

### Case Bridge (Procest)
- Convert a complaint to a formal case (zaak) in Procest with one action
- Case reference stored on the complaint record

## Data Model

New `klacht` schema in `pipelinq_register.json`:

| Field | Type | Description |
|-------|------|-------------|
| subject | string | Short complaint title |
| description | text | Full complaint details |
| category | ref | Complaint category |
| channel | enum | phone, email, counter, web, letter |
| status | enum | nieuw, in_behandeling, opgelost, gesloten, heropend |
| client | object ref | Linked client |
| receivedAt | datetime | When the complaint was received |
| deadline | date | SLA-based resolution deadline |
| assignedTo | user ref | Responsible agent |
| caseRef | string | Optional external Procest case ID |

## Impact

- **Data model**: New `klacht` and `klachtcategorie` schemas in `lib/Settings/pipelinq_register.json`
- **Frontend**: New complaint views, SLA indicator components, navigation item
- **Backend**: SLA deadline calculation, escalation notification job

## Specification

Full specification: `openspec/changes/archive/2026-03-22-klachtenregistratie/specs/`

Related changes:
- Design: `openspec/changes/archive/2026-03-22-klachtenregistratie/design.md`
- Tasks: `openspec/changes/archive/2026-03-22-klachtenregistratie/tasks.md`
