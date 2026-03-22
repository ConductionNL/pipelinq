## Context

Pipelinq is a thin-client CRM Nextcloud app that stores all data in OpenRegister (JSON object storage). The frontend (Vue 2.7 + Pinia) queries OpenRegister API directly. The backend is minimal: settings controller, repair steps for schema import, and service classes for configuration.

Currently, requests and leads can be assigned to individual users and placed on pipelines, but there is no structured queue system for workload distribution. Organizations need priority queues for FIFO processing and skill-based routing to match work to the right agent.

The existing data model includes: clients, contacts, leads, requests, pipelines, products, productCategories, and leadProducts -- all as OpenRegister schemas defined in `lib/Settings/pipelinq_register.json`.

## Goals / Non-Goals

**Goals:**
- Add `queue`, `skill`, and `agentProfile` schemas to the OpenRegister data model
- Add `queue` field to the `request` schema (lead schema already has flexible properties)
- Implement queue list/detail views in the frontend with priority-based ordering
- Implement skill definitions and agent profile management in admin settings
- Implement routing suggestion panel (advisory, not auto-assignment)
- Add "My Queues" tab to the My Work view
- Create default queues and skills via repair step

**Non-Goals:**
- Auto-assignment (routing is advisory only -- agents accept suggestions)
- Real-time queue notifications (use existing Nextcloud notification system later)
- SLA tracking within queues (separate future feature)
- Queue analytics/reporting dashboard (separate future feature)
- Round-robin or weighted distribution algorithms (keep it simple: skill match + workload sort)

## Decisions

### D1: Queue as OpenRegister schema (not pipeline extension)

**Decision**: Queues are a separate `queue` schema, not an extension of the existing `pipeline` schema.

**Rationale**: Pipelines represent visual workflow stages (kanban columns). Queues represent priority-ordered work buffers. They serve different purposes: a request can be in a queue (waiting for pickup) AND on a pipeline (tracking progress). Conflating them would complicate both concepts.

**Alternative considered**: Reuse pipeline with a `type: queue` flag. Rejected because pipeline semantics (ordered stages, drag-and-drop between columns) differ fundamentally from queue semantics (priority ordering, FIFO within priority).

### D2: Skill profiles as OpenRegister objects (not Nextcloud user metadata)

**Decision**: Agent skill profiles are stored as OpenRegister objects with `userId` as a key, rather than using Nextcloud's user metadata or preferences system.

**Rationale**: OpenRegister provides schema validation, API access, and consistency with the rest of the data model. Nextcloud's user metadata (IAccountManager) has limited structure and is harder to query for routing calculations. The agentProfile schema can evolve independently.

**Alternative considered**: Store skills in Nextcloud user preferences via IConfig. Rejected because querying "all users with skill X" would require scanning all users rather than querying OpenRegister.

### D3: Advisory routing (suggestions) over auto-assignment

**Decision**: The routing system suggests agents but does not auto-assign. An agent or supervisor must click to accept a suggestion.

**Rationale**: Auto-assignment is complex (race conditions, fairness algorithms, override mechanisms) and risky for government orgs where accountability matters. Starting with suggestions lets teams validate the skill matching before committing to automation. Auto-assignment can be added as a future enhancement.

### D4: Workload calculation via API count query

**Decision**: Agent workload is calculated by counting open assigned items via OpenRegister API at suggestion time, not maintained as a cached counter.

**Rationale**: With typical team sizes (5-50 agents) and queue sizes (10-100 items), the query cost is negligible. Cached counters would require event listeners and risk inconsistency. OpenRegister supports filtering by `assignee` and `status`, making this a straightforward aggregation.

**Alternative considered**: Maintain a `currentWorkload` counter on agentProfile, incremented/decremented on assignment changes. Rejected due to consistency risks and added complexity.

### D5: Frontend-only queue ordering (no backend sort)

**Decision**: Priority-based queue ordering is computed in the frontend by sorting fetched items, not enforced by backend sort order.

**Rationale**: OpenRegister supports `_order` on queries, so we can request items ordered by `priority` then `requestedAt`. However, priority ordering (urgent > high > normal > low) requires custom sort since it is string-based. The frontend already implements this sort logic for the My Work view (`src/views/MyWork.vue`). Reusing this pattern keeps the approach consistent.

### D6: Three new schemas added to register JSON

**Decision**: Add `queue`, `skill`, and `agentProfile` schemas to `pipelinq_register.json` and reference them in the `pipelinq` register's schema list.

Files affected:
- `lib/Settings/pipelinq_register.json`: Add 3 new schema definitions + `queue` field on `request` schema + register schema list update
- `lib/Repair/InitializeSettings.php`: Repair step already calls `ConfigurationService::importFromApp()` which reads the register JSON -- no changes needed to import logic

## Risks / Trade-offs

- **[Risk] Schema migration**: Adding `queue` field to request schema requires re-import. Existing requests will have `queue: null` which is the correct default. -> Mitigation: The repair step re-import handles schema evolution; no data migration needed.

- **[Risk] Workload query performance**: Counting open items per agent on every routing suggestion could be slow with many agents. -> Mitigation: Typical KCC teams have 5-50 agents. Query is filtered by assignee (indexed in OpenRegister). Caching can be added later if needed.

- **[Risk] Skill-category matching is string-based**: Categories on skills and requests are free-text strings. Typos or inconsistent naming break matching. -> Mitigation: Default skills and categories are seeded; admin UI shows existing categories as suggestions. Future: use SystemTags for formal taxonomy.

- **[Trade-off] No real-time updates**: Queue views don't auto-refresh when another agent picks an item. -> Mitigation: Standard Nextcloud polling pattern (manual refresh or periodic fetch). Real-time via websockets is a future enhancement.

## Migration Plan

1. **Schema addition**: Add `queue`, `skill`, `agentProfile` to `pipelinq_register.json`. Add `queue` field to `request` schema.
2. **Repair step**: Existing `InitializeSettings` repair step re-imports register JSON on app update. Default queues and skills are created if none exist.
3. **Frontend**: New views and components are additive. No existing views are broken.
4. **Rollback**: Removing the queue feature only requires removing the new schemas and frontend routes. Existing request data is unaffected (the `queue` field would simply be ignored).

## Open Questions

- Should leads also have a `queue` field, or are queues only for requests? The proposal mentions both, but the primary use case (KCC werkplek) is request-centric. **Decision: Start with requests only; add leads to queues in a follow-up if needed.**
- Should queue assignment trigger a Nextcloud activity event? Useful for audit trail but adds complexity. **Decision: Defer to activity-timeline spec enhancement.**
