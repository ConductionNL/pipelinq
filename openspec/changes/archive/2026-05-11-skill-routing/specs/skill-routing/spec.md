# Spec: Skill-Based Routing

## Purpose

Skill-based routing provides intelligent advisory work distribution by matching request categories to agent skill areas. Skills are defined by admins, assigned to agents via profiles, and matched against request categories to generate a ranked agent shortlist sorted by workload. The system is advisory-only — no auto-assignment. Demand score: 925 (308 tender mentions, omnichannel routing and workload distribution).

**Standards**: Schema.org (`schema:DefinedTerm` for skills, `schema:Person` for agent profiles)
**Feature tier**: Enterprise
**Extends**: queue-management

---

## REQ-SKR-001: Skill Definition Entity [Enterprise]

The system MUST store skill definitions as OpenRegister objects. Each skill represents an area of expertise that can be assigned to agents and matched against request categories for routing. Skills MUST be stored with `@type: schema:DefinedTerm`.

### Scenario: Create a skill

- GIVEN an authenticated admin
- WHEN the admin creates a skill with title "Vergunningen" and categories ["vergunningen", "omgevingsrecht"]
- THEN a new OpenRegister object MUST be created in the `skill` schema with `@type: schema:DefinedTerm`
- AND the skill MUST be retrievable via the skills API
- AND the skill MUST appear in agent profile assignment forms

### Scenario: List all skills in admin settings

- GIVEN an admin navigates to the skill management section in Pipelinq admin settings
- WHEN the page loads
- THEN all skill objects MUST be displayed with their title, category tags, and active status
- AND inactive skills MUST be visually distinguishable from active skills without relying solely on colour (WCAG AA)

### Scenario: Update a skill's categories

- GIVEN skill "Vergunningen" exists with categories ["vergunningen"]
- WHEN an admin adds "omgevingsrecht" to its categories
- THEN the updated categories MUST be persisted
- AND existing agent-skill assignments MUST remain valid

### Scenario: Deactivate a skill

- GIVEN skill "Vergunningen" exists with `isActive: true`
- WHEN an admin sets `isActive` to `false`
- THEN the skill MUST NOT be used for routing suggestions
- AND existing agent-skill assignments MUST remain stored (not deleted)

### Scenario: Delete a skill

- GIVEN a skill exists and is assigned to one or more agent profiles
- WHEN an admin deletes the skill
- THEN the skill OpenRegister object MUST be removed
- AND the skill UUID MUST be removed from all `agentProfile.skills` arrays where it appears

---

## REQ-SKR-002: Agent Skill Profile [Enterprise]

The system MUST store agent skill profiles as OpenRegister objects linking a Nextcloud user UID to assigned skills, an availability flag, and a maximum concurrent item count. Agent identity MUST be derived from Nextcloud — never from a frontend-supplied user ID.

### Scenario: Assign skills to an agent

- GIVEN an admin is editing the skill profile for agent "jan.devries"
- WHEN the admin assigns skills "Vergunningen" and "WMO / Zorg"
- THEN the agentProfile object for "jan.devries" MUST store both skill UUIDs in the `skills` array
- AND "jan.devries" MUST be eligible for routing suggestions for requests with matching categories

### Scenario: View agent skills

- GIVEN an admin views the agent profile for "jan.devries"
- WHEN the profile detail is shown
- THEN all assigned skills MUST be displayed with their titles resolved from skill objects

### Scenario: Remove a skill from an agent

- GIVEN agent "jan.devries" has skills ["Vergunningen", "WMO / Zorg"] in their profile
- WHEN an admin removes "WMO / Zorg"
- THEN the `skills` array MUST contain only the "Vergunningen" UUID
- AND "jan.devries" MUST NOT receive routing suggestions for WMO/Zorg categories

### Scenario: Set agent as unavailable

- GIVEN agent "jan.devries" has `isAvailable: true`
- WHEN an admin sets `isAvailable` to `false`
- THEN "jan.devries" MUST NOT appear in any routing suggestions
- AND existing assignments on open requests MUST remain unchanged

### Scenario: Set maximum concurrent items

- GIVEN admin sets "jan.devries" `maxConcurrent` to 5
- WHEN "jan.devries" already has 5 or more open assigned items
- THEN the routing system MUST NOT include "jan.devries" in suggestions
- AND the panel MUST indicate that a matching agent is at capacity

---

## REQ-SKR-003: Skill-Based Routing Suggestions [Enterprise]

The system MUST display advisory routing suggestions when a request is queued, matching the request's `category` field against agent skill definitions. Suggestions are advisory — no auto-assignment occurs.

### Scenario: Suggestions shown for a categorised request

- GIVEN request "Aanvraag parkeervergunning" has category "vergunningen" and is assigned to a queue
- WHEN an agent opens the request detail view
- THEN a "Suggested agents" panel MUST be displayed
- AND agents whose assigned skills include a skill with "vergunningen" in `categories` MUST appear in the list
- AND agents MUST be sorted by current workload ascending (fewest open items first)
- AND each suggestion MUST display the agent's name, current workload (e.g., "3/8 items"), and matched skill name

### Scenario: No matching agents available

- GIVEN a request has category "niche-subject" and no active skill covers that category
- WHEN the routing suggestion panel is shown
- THEN the panel MUST display "No agents with matching skills"
- AND the panel MUST still allow manual assignment to any agent

### Scenario: At-capacity agents excluded with notice

- GIVEN agent "jan.devries" has `maxConcurrent: 5` and 5 open assigned items
- WHEN routing suggestions are calculated for a request matching "jan.devries" skill
- THEN "jan.devries" MUST NOT appear in the suggestions list
- AND the panel MUST display a notice stating how many matching agents are at capacity

### Scenario: Unavailable agents excluded

- GIVEN agent "jan.devries" has `isAvailable: false`
- WHEN routing suggestions are calculated for any request
- THEN "jan.devries" MUST NOT appear in the suggestions list regardless of skill match

### Scenario: Accept a routing suggestion

- GIVEN the routing suggestion panel shows "jan.devries" as a suggested agent
- WHEN an agent clicks "Assign" next to "jan.devries"
- THEN the request's `assignee` field MUST be set to "jan.devries"
- AND the suggestion panel MUST close
- AND the assignment MUST be persisted via the standard request update

### Scenario: No-category request shows all available agents

- GIVEN a request has no `category` set
- WHEN the routing suggestion panel is displayed
- THEN ALL available agents (isAvailable=true and under capacity) MUST be shown sorted by workload ascending
- AND no skill-match filtering MUST be applied

---

## REQ-SKR-004: Workload Calculation [Enterprise]

The system MUST calculate each agent's current workload as the count of non-terminal requests and open leads assigned to them. This value is used for routing suggestion sorting and workload display.

### Scenario: Workload counts only non-terminal items

- GIVEN "jan.devries" has: 1 request with status "new", 1 request with status "in_progress", 1 request with status "completed"; and 1 lead with status "open", 1 lead with status "closed"
- WHEN the workload is calculated for "jan.devries"
- THEN the workload count MUST be 3 (2 open requests + 1 open lead)
- AND the completed request and closed lead MUST NOT be counted

### Scenario: Workload displayed per agent in suggestion panel

- GIVEN routing suggestions are displayed for a request
- WHEN each suggested agent is listed
- THEN each agent row MUST display their current workload in "N/M items" format where N is current open items and M is maxConcurrent

---

## REQ-SKR-005: Default Skill Seeding [Enterprise]

The system MUST create default skills during the repair step if no skills exist, providing an out-of-box experience for Dutch government KCC deployments. The repair step MUST be idempotent.

### Scenario: Default skills created on first install

- GIVEN the Pipelinq repair step runs and no skill objects exist in OpenRegister
- WHEN the repair step executes
- THEN the system MUST create the following 5 default skills:
  - "Algemene Dienstverlening" — categories: ["algemeen"], isActive: true
  - "Vergunningen" — categories: ["vergunningen", "omgevingsrecht"], isActive: true
  - "Belastingen" — categories: ["belastingen"], isActive: true
  - "WMO / Zorg" — categories: ["wmo", "zorg"], isActive: true
  - "Klachten" — categories: ["klachten"], isActive: true

### Scenario: Repair step does not duplicate skills

- GIVEN skill objects already exist in OpenRegister
- WHEN the repair step runs again
- THEN the system MUST NOT create duplicate skill objects
- AND existing skill data MUST remain unchanged

---

## REQ-SKR-006: Routing Suggestion API Endpoint [Enterprise]

The system MUST expose a `GET /api/routing/suggestions` endpoint returning a ranked agent shortlist for a given request or lead. The endpoint requires authentication and MUST NOT expose internal data to unauthenticated callers.

### Scenario: Valid request returns suggestion list

- GIVEN an authenticated agent
- WHEN `GET /api/routing/suggestions?entityType=request&entityId={uuid}` is called for a request with category "vergunningen"
- THEN the response status MUST be 200
- AND `suggestions` MUST contain agents with matching skills, sorted by workload ascending
- AND each suggestion MUST include `userId`, `workload`, `maxConcurrent`, and `matchedSkill`
- AND `atCapacity` MUST reflect how many skill-matching agents were excluded due to capacity
- AND `noMatch` MUST be `false` when at least one suggestion is returned

### Scenario: Missing required parameters return 400

- GIVEN an authenticated agent
- WHEN `GET /api/routing/suggestions` is called without `entityType` or `entityId`
- THEN the response status MUST be 400
- AND the response MUST contain a `message` field with a user-readable static error string
- AND the response MUST NOT contain stack traces, SQL, or internal paths

### Scenario: Unauthenticated request returns 401

- GIVEN no valid Nextcloud session is present
- WHEN `GET /api/routing/suggestions` is called
- THEN the response status MUST be 401
- AND no suggestion data MUST be returned

---

## REQ-SKR-007: Skill and Agent Profile Management in Admin Settings [Enterprise]

The system MUST surface skill and agent profile management as sections within the Pipelinq admin settings page. Only Nextcloud administrators MUST be able to manage skills and agent profiles.

### Scenario: Admin can manage skills in settings

- GIVEN an authenticated admin is on the Pipelinq admin settings page
- WHEN the admin views the "Skill routing" section
- THEN a list of all skill objects MUST be shown
- AND the admin MUST be able to create, edit, deactivate, and delete skills from this section

### Scenario: Admin can manage agent profiles in settings

- GIVEN an authenticated admin views the agent profiles section in admin settings
- WHEN the admin selects an agent
- THEN the admin MUST be able to add or remove skill assignments and configure availability and maxConcurrent

### Scenario: Non-admin users cannot access skill management endpoints

- GIVEN an authenticated non-admin Nextcloud user
- WHEN the user attempts to call the skill or agentProfile CRUD endpoints without admin rights
- THEN the response status MUST be 403
- AND no skill or profile data MUST be modified
