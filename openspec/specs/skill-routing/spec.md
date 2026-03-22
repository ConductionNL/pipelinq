# Skill-Based Routing Specification

## Purpose

Skill-based routing enables intelligent work distribution by matching requests to agents based on their expertise areas. Skills are defined by admins, assigned to agents via profiles, and matched against request categories to generate advisory routing suggestions. The system is advisory-only (no auto-assignment) to maintain human accountability.

**Standards**: Schema.org (`DefinedTerm` for skills, `Person` for agent profiles)
**Feature tier**: Enterprise

---

## Data Model

### Skill Entity

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `title` | string | `schema:name` | Yes | -- |
| `description` | string | `schema:description` | No | -- |
| `categories` | array of string | `schema:category` | No | [] |
| `isActive` | boolean | -- | No | true |

### Agent Profile Entity

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `userId` | string | -- | Yes | -- |
| `skills` | array of string (UUIDs) | -- | No | [] |
| `maxConcurrent` | integer | -- | No | 10 |
| `isAvailable` | boolean | -- | No | true |

---

## Requirements

### Requirement: Skill Definition Entity [Enterprise]

The system SHALL support defining skills as OpenRegister objects. Skills represent areas of expertise that can be assigned to agents and matched to request categories for routing. Each skill SHALL be stored with `@type` set to `schema:DefinedTerm`.

#### Scenario: Create a skill
- **WHEN** an admin creates a skill with title "Vergunningen" and categories ["vergunningen", "omgevingsrecht"]
- **THEN** the system SHALL create an OpenRegister object with `@type` set to `schema:DefinedTerm`
- **THEN** the skill SHALL be available for assignment to users

#### Scenario: List all skills
- **WHEN** an admin views the skill list in admin settings
- **THEN** all skills SHALL be displayed with their title, description, category mappings, and active status

#### Scenario: Update a skill
- **WHEN** an admin updates skill "Vergunningen" to add category "bestemmingsplan"
- **THEN** the updated categories SHALL be persisted
- **THEN** existing agent-skill assignments SHALL remain valid

#### Scenario: Deactivate a skill
- **WHEN** an admin sets skill "Vergunningen" isActive to false
- **THEN** the skill SHALL no longer be used for routing suggestions
- **THEN** existing agent-skill assignments SHALL be preserved but inactive

#### Scenario: Delete a skill
- **WHEN** an admin deletes a skill
- **THEN** the skill object SHALL be removed from OpenRegister
- **THEN** the skill SHALL be removed from all agent skill profiles

---

### Requirement: Agent Skill Profile [Enterprise]

The system SHALL support assigning skills to Nextcloud users. An agent's skill profile is stored as an OpenRegister object linking the user UID to their assigned skills and current workload metadata.

#### Scenario: Assign skills to an agent
- **WHEN** an admin assigns skills "Vergunningen" and "WMO" to user "jan.devries"
- **THEN** the agent profile SHALL store both skill UUIDs in the `skills` array
- **THEN** "jan.devries" SHALL be eligible for routing of matching requests

#### Scenario: View agent skills
- **WHEN** an admin views agent "jan.devries" skill profile
- **THEN** the system SHALL display all assigned skills with their titles

#### Scenario: Remove a skill from an agent
- **WHEN** an admin removes skill "WMO" from "jan.devries"
- **THEN** the `skills` array SHALL be updated to exclude the WMO skill UUID
- **THEN** "jan.devries" SHALL no longer receive routing suggestions for WMO categories

#### Scenario: Set agent availability
- **WHEN** an admin sets "jan.devries" isAvailable to false
- **THEN** "jan.devries" SHALL be excluded from routing suggestions
- **THEN** existing assignments SHALL remain unchanged

#### Scenario: Set max concurrent items
- **WHEN** an admin sets "jan.devries" maxConcurrent to 5
- **THEN** the routing system SHALL not suggest "jan.devries" when they already have 5 or more open assigned items

---

### Requirement: Skill-Based Routing Suggestion [Enterprise]

The system SHALL suggest agents for assignment based on skill match and current workload when a request or lead is added to a queue or its category changes. Routing is advisory (suggestions) rather than mandatory (auto-assignment).

#### Scenario: Suggest agents based on category match
- **WHEN** request "Aanvraag parkeervergunning" with category "vergunningen" is added to a queue
- **THEN** the system SHALL display a "Suggested agents" list
- **THEN** agents whose skills include a skill with "vergunningen" in its categories SHALL appear in the list
- **THEN** agents SHALL be sorted by current workload (fewest open items first)

#### Scenario: No matching agents
- **WHEN** a request has category "niche-topic" and no agents have a matching skill
- **THEN** the system SHALL display "No agents with matching skills"
- **THEN** the system SHALL still allow manual assignment to any agent

#### Scenario: Agent at capacity excluded
- **WHEN** agent "jan.devries" has maxConcurrent 5 and currently has 5 open assigned items
- **THEN** "jan.devries" SHALL NOT appear in routing suggestions
- **THEN** a note SHALL indicate "1 matching agent at capacity"

#### Scenario: Unavailable agent excluded
- **WHEN** agent "jan.devries" has isAvailable set to false
- **THEN** "jan.devries" SHALL NOT appear in routing suggestions

#### Scenario: Accept routing suggestion
- **WHEN** an agent clicks "Assign" next to a suggested agent in the suggestion list
- **THEN** the item's `assignee` field SHALL be set to the selected agent's UID
- **THEN** the suggestion panel SHALL close

#### Scenario: Category-less request shows all available agents
- **WHEN** a request has no category set
- **THEN** the system SHALL display all available agents sorted by workload
- **THEN** no skill-match filtering SHALL be applied

---

### Requirement: Workload Calculation [Enterprise]

The system SHALL calculate an agent's current workload as the count of open items (requests with non-terminal status + leads in non-closed stages) assigned to them. This count is used for routing suggestions and queue dashboard metrics.

#### Scenario: Count open items for agent
- **WHEN** "jan.devries" has 3 requests (status: new, in_progress, completed) and 2 leads (stages: Contacted, Won)
- **THEN** the workload count SHALL be 3 (2 open requests + 1 open lead; completed request and Won lead are excluded)

#### Scenario: Workload displayed in routing suggestions
- **WHEN** routing suggestions are shown for a request
- **THEN** each suggested agent SHALL display their current workload count (e.g., "3/10 items")

---

### Requirement: Default Skills [Enterprise]

The system SHALL create default skills during the repair step to provide an out-of-box experience for Dutch government (KCC) use cases.

#### Scenario: Default skills created on install
- **WHEN** the Pipelinq repair step runs and no skills exist
- **THEN** the system SHALL create the following default skills:
  - "Algemene Dienstverlening" with categories ["algemeen"]
  - "Vergunningen" with categories ["vergunningen", "omgevingsrecht"]
  - "Belastingen" with categories ["belastingen"]
  - "WMO / Zorg" with categories ["wmo", "zorg"]
  - "Klachten" with categories ["klachten"]

#### Scenario: Default skills not duplicated
- **WHEN** the repair step runs and skills already exist
- **THEN** the system SHALL NOT create duplicate skills

---

### Current Implementation Status

**Implemented:**
- **Skill Definition Entity:** Fully implemented. Skill schema defined in `lib/Settings/pipelinq_register.json` with `@type: schema:DefinedTerm`. CRUD via `src/store/modules/skills.js` Pinia store. Admin UI in `src/components/admin/SkillSettings.vue`.
- **Agent Skill Profile:** Fully implemented. AgentProfile schema in register JSON. CRUD via `src/store/modules/agentProfiles.js`. Admin UI in `src/components/admin/AgentProfileSettings.vue` with skill checkbox assignment.
- **Skill-Based Routing Suggestion:** Implemented in `src/components/RoutingSuggestionPanel.vue`. Uses `findMatchingAgents()`, `filterByCapacity()`, `sortByWorkload()` from `src/services/queueUtils.js`. Integrated into `RequestDetail.vue`.
- **Workload Calculation:** Implemented in `agentProfiles.js` store via `getWorkload()` method. Counts open requests (non-terminal status) and open leads (status=open).
- **Default Skills:** Created via `DefaultQueueService::createDefaultSkills()` called from repair step. Creates 5 default skills for Dutch government KCC use cases.
