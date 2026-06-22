# KCC Werkplek — Declarative Dashboard Rebuild

**Spec refs**: `kcc-werkplek`, ADR-022 (apps consume OR + library abstractions), ADR-036 (declarative dashboard manifest), `cn-workspace-context-widgets` (nextcloud-vue — workspace context, `@workspace.*` tokens, `interaction-form` / `kb-search` widgets, `CnResourceSelect`)
**Standards**: WCAG 2.1 AA (standard page chrome + keyboard-reachable widgets)

## MODIFIED Requirements

### Requirement: Workspace Layout and Navigation

The KCC werkplek MUST be rendered as a single declarative `type: "dashboard"` page on the
standard library page chrome (one page header, one `actionsComponent` action bar, one scroll
region), composed of library widgets on the dashboard grid — NOT a bespoke multi-panel page
with independently-scrolling columns. Work is split into separate **Requests** and **Tasks**
widgets; a **queue filter** widget narrows both; an **active-interaction** form, a
**knowledge-base** search, and **client-overview** lists complete the grid. The page MUST
scroll as one region with no cut-off action buttons.

**Feature tier**: MVP

#### Scenario: Declarative dashboard with separate work widgets

- GIVEN an agent opens the KCC werkplek
- WHEN the page loads
- THEN the system MUST render a `type: "dashboard"` page with a standard page header and an action bar (`actionsComponent`)
- AND Requests and Tasks MUST be two distinct `object-list` widgets (not a single fused inbox)
- AND the page MUST be a single scroll region with no action buttons clipped off-screen

#### Scenario: Queue filter narrows the work lists

- GIVEN the werkplek is loaded and the queue filter widget lists the agent's queues with open-request counts
- WHEN the agent clicks a queue
- THEN the Requests and Tasks widgets MUST re-query filtered to that queue (via the `@workspace.selectedQueue` page context written by the filter)
- AND clicking "All queues" MUST clear the filter so both lists show all of the agent's work

#### Scenario: Standard chrome carries the availability toggle

- GIVEN the werkplek is loaded
- WHEN the agent looks at the page header
- THEN the agent-availability toggle MUST be present in the header action bar (the page's `actionsComponent`), hydrated from `GET /api/kcc-werkplek/state`

---

### Requirement: Contact Moment Registration

The active-interaction registration MUST be a single library `interaction-form` widget that
persists a contactmoment to OpenRegister and drives the rest of the page. Its client picker
MUST allow creating a client inline from the typed search term (no dead "no results" path).
Selecting or creating a client MUST write the client into the page workspace context, and
typing the summary MUST stream the summary text into the page workspace context, so sibling
widgets react.

**Feature tier**: MVP

#### Scenario: Register a contactmoment from the interaction widget

- GIVEN an agent has a channel, a client, and a subject filled in the interaction widget
- WHEN the agent clicks Register
- THEN the system MUST persist a contactmoment to OpenRegister with the channel, client, subject, summary, and outcome
- AND MUST clear the per-interaction fields (subject/summary/outcome) while keeping the client for follow-ups

#### Scenario: Create a client inline from the search term

- GIVEN an agent types a client name in the interaction widget's client picker that matches no existing client
- WHEN the agent picks the "Create '<name>'" option
- THEN the system MUST create a new client object with that name and select it
- AND MUST write the new client id into the page workspace context (`selectedClient`)

#### Scenario: Selecting a client reveals their overview

- GIVEN an agent selects (or creates) a client in the interaction widget
- WHEN the workspace context's `selectedClient` is set
- THEN the client-overview widgets (that client's requests and recent contact moments) MUST load and display that client's records
- AND before any client is selected, those widgets MUST show a prompt instead of fetching the whole register

---

### Requirement: Knowledge Base Integration in Workspace

The knowledge base MUST be a library `kb-search` widget bound to the active interaction
summary: as the agent types the summary, the widget MUST search the knowledge endpoint
(the OpenRegister xWiki integration leaf) on the live summary text. Manual typing in the
widget's own search box MUST override the bound summary. The widget MUST degrade gracefully —
an empty result or an unavailable/erroring backend MUST render an empty/unavailable state, never
a page error.

**Feature tier**: MVP

#### Scenario: Summary drives the knowledge search

- GIVEN the agent is typing a summary in the interaction widget
- WHEN the summary text reaches the minimum length
- THEN the knowledge base widget MUST query the configured endpoint with the summary text (debounced)
- AND MUST render the returned articles as a clickable list

#### Scenario: Knowledge base degrades gracefully

- GIVEN the knowledge endpoint returns nothing, a 503, or a network error
- WHEN the widget runs a search
- THEN the widget MUST render an empty-or-unavailable state
- AND MUST NOT raise a console error or break the page

#### Scenario: Manual search overrides the bound summary

- GIVEN a summary-driven suggestion is showing
- WHEN the agent types their own query in the knowledge widget's search box
- THEN the widget MUST search the typed query instead of the summary
- AND clearing the box MUST hand control back to the bound summary
