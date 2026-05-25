# Proposal: kcc-werkplek

## Problem

Pipelinq has no unified workspace for KCC (Klant Contact Centrum) agents. Agents handling citizen interactions must navigate between multiple disconnected views — contactmomenten, requests, tasks, queues, and the knowledge base — during live calls. This causes slow handling times, poor first-call resolution rates, and missed follow-ups. 308 tender mentions (demand score: 925) explicitly require omnichannel customer communication management as a core capability.

There is no single screen where an agent can see their incoming work queue, register a contactmoment while talking with a citizen, consult the knowledge base, and toggle their availability — all without leaving the workspace.

## Solution

Implement a KCC Werkplek — a dedicated, unified agent workspace that consolidates:

1. **Omnichannel inbox panel** showing assigned requests, open tasks, and queue counts with priority ordering
2. **Quick contactmoment form** with channel selector, integrated call timer, client autocomplete, and outcome field
3. **Inline knowledge base search** enabling first-call resolution without navigating away
4. **Agent availability toggle** updating the agentProfile for skill-based routing
5. **Active session panel** showing context for the current interaction

### Approach

- Add a `/werkplek` route and `KccWerkplekPage.vue` as the primary entry point for KCC agents
- Create `KccWerkplekController.php` with a single aggregated workspace state endpoint
- Build `KccWerkplekService.php` to query assigned requests, open tasks, queue capacities, and agent profile
- Implement workspace panel components reusing existing `CallTimer.vue` and knowledge base stores
- Extend `MainMenu.vue` with a top-level "KCC Werkplek" navigation entry

No new OpenRegister schemas are needed — all required entities (`contactmoment`, `request`, `task`, `queue`, `kennisartikel`, `agentProfile`, `skill`) already exist in the pipelinq register.

## Scope

- KCC Werkplek page with three-panel layout (inbox | active interaction | knowledge search)
- Queue/inbox panel listing assigned requests and open tasks grouped by priority
- Quick contactmoment form with channel adaptation and call timer
- Inline knowledge base search with article preview and feedback buttons
- Agent availability toggle (Beschikbaar / Niet beschikbaar)
- Workspace state API endpoint aggregating queue and assignment data
- Navigation integration with headset icon as primary KCC entry point

## Out of scope

- Real-time push notifications and WebSocket updates (V2)
- Supervisor/team dashboard with live agent overview (separate change)
- CTI screen pop integration (Enterprise)
- Nextcloud Talk embedded calling (Enterprise)
- KTO survey dispatch from workspace (V2)
- Multi-queue simultaneous handling (V2)
