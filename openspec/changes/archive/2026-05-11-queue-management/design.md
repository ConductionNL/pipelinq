# Design: queue-management

## Architecture Overview

Queue management adds priority-ordered work queues to Pipelinq, enabling workload distribution across teams and agents. The implementation follows the thin-client pattern: queues, skills, and agent profiles are stored as OpenRegister objects; the frontend queries OpenRegister directly; backend services handle default data creation, overflow logic, and routing.

## Components

### Backend (PHP)

| Component | Path | Purpose |
|-----------|------|---------|
| `DefaultQueueService` | `lib/Service/DefaultQueueService.php` | Creates default queues and skills during repair |
| `QueueService` | `lib/Service/QueueService.php` | Queue operations: capacity check, overflow routing, assignment |
| `QueueOverflowJob` | `lib/BackgroundJob/QueueOverflowJob.php` | Periodic check for queues exceeding max wait time / capacity |

### Frontend (Vue)

| Component | Path | Purpose |
|-----------|------|---------|
| `QueueList.vue` | `src/views/queues/QueueList.vue` | Queue grid with depth, agent count, categories |
| `QueueDetail.vue` | `src/views/queues/QueueDetail.vue` | Priority-sorted item list, pick next, bulk assign |
| `RoutingSuggestionPanel.vue` | `src/components/RoutingSuggestionPanel.vue` | Skill-based agent suggestions with workload |
| `QueueSettings.vue` | `src/components/admin/QueueSettings.vue` | Admin queue configuration |
| `SkillSettings.vue` | `src/components/admin/SkillSettings.vue` | Admin skill management |
| `AgentProfileSettings.vue` | `src/components/admin/AgentProfileSettings.vue` | Agent profile/skill assignment |
| `queues.js` | `src/store/modules/queues.js` | Pinia store for queue CRUD |
| `skills.js` | `src/store/modules/skills.js` | Pinia store for skill CRUD |
| `agentProfiles.js` | `src/store/modules/agentProfiles.js` | Pinia store for agent profiles |
| `queueUtils.js` | `src/services/queueUtils.js` | Priority sort, capacity check, routing utils |

### Data Model

Schemas are defined in `lib/Settings/pipelinq_register.json`:

- **queue** (`schema:ItemList`): title, description, categories, isActive, maxCapacity, sortOrder, assignedAgents, overflowQueue
- **skill** (`schema:DefinedTerm`): title, description, categories, isActive
- **agentProfile** (`schema:Person`): userId, skills (array of skill UUIDs), isAvailable, maxConcurrent

The `request` schema includes a `queue` field (UUID) and `priority` field for queue membership.

### Routing

Frontend routes: `/queues` (list) and `/queues/:id` (detail) registered in `src/router/index.js`.
Navigation: "Queues" item in `src/navigation/MainMenu.vue`.

## Seed Data

Default queues created by `DefaultQueueService::createDefaultQueues()`:
- "Algemeen" (General) - categories: []
- "Vergunningen" (Permits) - categories: ["vergunningen"]
- "Klachten" (Complaints) - categories: ["klachten"]

Default skills created by `DefaultQueueService::createDefaultSkills()`:
- "Algemene Dienstverlening", "Vergunningen", "Belastingen", "WMO / Zorg", "Klachten"

## Design Decisions

1. **Queue per item, not per list**: Items reference their queue via UUID field, not stored in queue arrays. This leverages OpenRegister filtering.
2. **Frontend-driven routing**: Skill-based routing suggestions computed client-side via `queueUtils.js`. No server-side routing engine needed for MVP.
3. **Background overflow**: `QueueOverflowJob` periodically checks queue capacity and max wait time, moving items to overflow queues when thresholds are exceeded.
4. **Priority ordering**: Client-side sort via `prioritySortComparator()` -- urgent > high > normal > low, then oldest first within same priority.
