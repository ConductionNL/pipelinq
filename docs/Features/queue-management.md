# Queue Management & Skill-Based Routing

**Status:** Planned

## Overview

Priority queues and skill-based routing for organizing requests and leads into named work queues, ensuring items reach the most qualified available agent. Required for government KCC deployments where multiple teams handle different inquiry types.

## Standards

- **GEMMA Klantgeleidingcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-4fb80905-d79b-4cde-aeab-7459fec668b1)
- **TEC CRM**: Section 3.2 (Assigning Cases), Section 3.3 (Escalating Unresolved Cases)
- **TEC CRM**: Section 1.6 (Territory Management, Team Selling, and Member Reassignment)

## Market Demand

Queue management and skill-based routing are among the most requested capabilities in government CRM/KCC tooling, particularly for Dutch municipalities implementing the KCC-werkplek model (validated across 97K requirements from 39K+ tenders).

## Key Capabilities

### Queue Entity
- Named, configurable queues (e.g., "Algemene Zaken", "Vergunningen", "Klachten")
- Queue types: request queue, lead queue, mixed
- Queue CRUD via admin settings
- Queue membership: requests and leads can be placed in a queue

### Priority-Based Ordering
Items within a queue are automatically sorted by:
1. Priority: urgent > high > normal > low
2. Age: oldest item first within the same priority level

### Skill-Based Routing (`skill-routing`)
- Skill tag system for Nextcloud users: admins tag agents with expertise areas (e.g., "vergunningen", "belastingen", "WMO")
- Skill-to-category mapping: when a request enters a queue, the system evaluates available agents whose skills match the request category
- Routing suggestion: the system suggests the best-matched, least-loaded agent
- Auto-assign mode: optional automatic assignment without manual confirmation

### Workload Balancing
- Agent workload indicator: current open items per agent
- Round-robin fallback: when skill scores are equal, assign to the least-loaded agent

### Queue Dashboard View
- Queue depth per queue (item count by priority)
- Average wait time in queue
- Agent workload table (open items per agent)

## Data Model

Two new schemas in `pipelinq_register.json`:

| Schema | Key Fields |
|--------|-----------|
| `queue` | name, description, type (request/lead/mixed), isActive, routingMode |
| `skill` | name, slug, description, category |

Existing schemas modified:
- `request`: gains `queue` (UUID reference, optional)
- `lead`: gains `queue` (UUID reference, optional)
- Nextcloud user profile: gains `skills[]` array (managed via admin settings)

## Impact

- **Data model**: New `queue` and `skill` schemas; `request` and `lead` schemas gain `queue` field
- **Frontend**: Queue list/detail views, queue dashboard widget, skill management in admin settings, queue column in request/lead list views
- **My Work**: "My Work" view gains a queue-based section showing items from the agent's assigned queues
- **Admin Settings**: Queue configuration and skill management sections

## Specification

Full specification: `openspec/changes/archive/2026-03-22-queue-management/specs/`

Related changes:
- Design: `openspec/changes/archive/2026-03-22-queue-management/design.md`
- Tasks: `openspec/changes/archive/2026-03-22-queue-management/tasks.md`
