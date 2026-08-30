# Proposal: skill-routing

## Problem

Pipelinq queue management has no intelligent work distribution. Requests arriving in queues are manually assigned to agents without regard for expertise — a permits specialist may handle a social-care inquiry while an agent trained in WMO sits idle processing parking tickets. 308 Dutch tender evaluations (demand score: 925) require omnichannel routing and intelligent workload distribution. This gap increases handling time, callbacks, and citizen wait times.

There is also no mechanism for advisory decision-support routing rules (demand score: 270) or any per-skill access scoping (demand score: 182). Teams working in specialised queues have no tooling to surface the right agent at the right time.

## Solution

Extend queue management with a skill-based routing layer:

1. **Skill definitions** — admins define named skill areas (e.g., "Vergunningen", "WMO / Zorg") tagged with categories that match against request category fields
2. **Agent profiles** — admins assign skills to Nextcloud users, configure availability, and set maximum concurrent workload
3. **Routing suggestions** — when a request is queued, the system matches the request's category against agent skills and surfaces a ranked shortlist sorted by current workload (fewest open items first)
4. **Workload tracking** — open item count per agent derived live from requests (non-terminal status) and leads (open stage)

Routing is advisory-only: suggestions are displayed but a human confirms every assignment, maintaining accountability.

### Approach

- Reuse existing `skill` and `agentProfile` schemas from ADR-000 (no new entities needed)
- Add `RoutingService` for category-to-skill matching and workload calculation
- Add `RoutingController` exposing `GET /api/routing/suggestions`
- Add `RoutingSuggestionPanel.vue` Vue component embedded in `RequestDetail.vue`
- Extend admin settings with `SkillSettings.vue` and `AgentProfileSettings.vue`
- Add `createDefaultSkills()` to `DefaultQueueService` for repair-step seeding

## Scope

- Skill CRUD in admin settings (title, description, category tags, active toggle)
- Agent profile management: skill assignment, availability toggle, max concurrent config
- `GET /api/routing/suggestions` — ranked agent shortlist for a queued request or lead
- Workload calculation: live count of open requests + open leads per agent
- `RoutingSuggestionPanel.vue` embedded in `RequestDetail.vue` queue section
- Default skills seeded at install (5 Dutch KCC skill areas)
- Seed data for `skill` and `agentProfile` schemas in `pipelinq_register.json`

## Out of scope

- Automatic assignment without human confirmation (no-touch routing) — V2
- Round-robin or capacity-balanced auto-distribution — V2
- Skill proficiency levels (beginner/intermediate/expert) — V2
- Real-time agent presence from telephony (available/busy) — Enterprise
- Cross-app routing to Procest cases — cross-app, separate PR
