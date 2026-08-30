## Why

Pipelinq currently supports basic request assignment (one user at a time via a user picker) and priority levels, but lacks structured queue management. As organizations scale, they need priority queues to ensure urgent items are handled first, skill-based routing to match requests to the right agent, and workload balancing to prevent bottleneck on individual team members. This is a common Enterprise-tier CRM requirement (see FEATURES.md: "Automated assignment rules" and "Workload analytics") and a key differentiator against lightweight CRMs that lack routing intelligence.

Market intelligence from 97K requirements across 39K tenders confirms that queue management and skill-based routing are among the most requested capabilities in government CRM/KCC tooling, particularly for Dutch municipalities implementing the KCC-werkplek model.

## What Changes

- Add a **Queue** entity to organize work items (requests and leads) into named, prioritized queues (e.g., "Algemene Zaken", "Vergunningen", "Klachten")
- Add a **Skill** tagging system for Nextcloud users, allowing admins to tag agents with skills/expertise areas (e.g., "vergunningen", "belastingen", "WMO")
- Implement **priority-based ordering** within queues: items are automatically sorted by priority (urgent > high > normal > low), then by age (oldest first within same priority)
- Implement **skill-based routing**: when a request enters a queue, the system suggests or auto-assigns it to an available agent whose skills match the request's category
- Add a **queue dashboard view** showing queue depths, wait times, and agent workload
- Add **admin settings** for queue configuration, skill definitions, and routing rules

## Capabilities

### New Capabilities
- `queue-management`: Queue entity CRUD, queue membership for requests/leads, priority-based ordering within queues, queue list and detail views
- `skill-routing`: Skill definitions for users, skill-to-category mapping, automatic agent suggestion/assignment based on skill match and current workload

### Modified Capabilities
- `request-management`: Requests gain a `queue` reference field and queue-aware status transitions (queued state)
- `my-work`: "My Work" view gains a queue-based section showing items from the agent's assigned queues
- `admin-settings`: Admin settings page gains queue configuration and skill management sections

## Impact

- **Data model**: New `queue` and `skill` schemas in `pipelinq_register.json`; `request` schema gains `queue` field
- **Frontend**: New queue list/detail views, queue dashboard widget, skill management in admin settings, queue column in request list
- **Backend**: New `QueueService` for routing logic, skill matching, and workload calculation; new repair step for default queues
- **API**: OpenRegister API used for queue/skill CRUD; no new custom API endpoints needed (thin client pattern)
- **Procest**: No direct impact; queue assignment is internal to Pipelinq before case conversion
- **Nextcloud integration**: Uses existing user management for skill tagging (IUserManager for user lookup)
