# Skill-based routing

**Status:** Planned

## Overview

Skill-based routing sends a ticket to the agent best placed to handle it, instead of parking it in a named bucket somebody has to watch. Agents are tagged with expertise areas; a ticket's category is matched against those tags. Required for government KCC deployments where several teams handle different inquiry types.

## Standards

- **GEMMA Klantgeleidingcomponent**: [gemmaonline.nl](https://gemmaonline.nl/index.php/GEMMA/id-4fb80905-d79b-4cde-aeab-7459fec668b1)
- **TEC CRM**: Section 3.2 (Assigning Cases), Section 3.3 (Escalating Unresolved Cases)
- **TEC CRM**: Section 1.6 (Territory Management, Team Selling, and Member Reassignment)

## Market Demand

Routing and workload balancing are among the most requested capabilities in government CRM/KCC tooling, particularly for Dutch municipalities implementing the KCC-werkplek model (validated across 97K requirements from 39K+ tenders).

## There is one queue, and it is a filter

Pipelinq has no queue records. The queue is every open ticket nobody has picked up, and it lives on one page: **Queue**. Filter it by ticket type, priority or channel to see the slice you work on.

Assign a ticket and it leaves the queue for that person's **My Work**. **All tickets** shows everything either way, assigned or not. Three surfaces, one rule: a ticket is either waiting for someone, or it is somebody's.

Named queues were retired because they were a second place to look. An agent had to know which queue their work sat in before they could start, routing had to keep the buckets balanced, and a ticket routed to the wrong bucket was invisible rather than merely unassigned.

## Key capabilities

### Skill-based routing (`skill-routing`)
- Skill tag system for Nextcloud users: admins tag agents with expertise areas (e.g. "vergunningen", "belastingen", "WMO")
- Skill-to-category mapping: a ticket's category is evaluated against the skills of available agents
- Routing suggestion: the system suggests the best-matched, least-loaded agent
- Auto-assign mode: optional automatic assignment without manual confirmation

### Workload balancing
- Agent workload indicator: current open items per agent
- Round-robin fallback: when skill scores are equal, assign to the least-loaded agent

## Data model

One schema in `pipelinq_register.json`:

| Schema | Key fields |
|--------|-----------|
| `skill` | name, slug, description, category |

The `ticket` schema carries `assignee` (a Nextcloud UID) and `status`. Those two fields are what the Queue page filters on: unassigned and still open. Nothing else is needed to express a queue.

## Impact

- **Data model**: the `skill` schema; agent profiles gain a `skills[]` array (managed via admin settings)
- **Frontend**: the Queue page, routing suggestions on ticket detail, skill management in admin settings
- **Admin settings**: skill management

## Specification

Full specification: `openspec/specs/skill-routing/spec.md`
