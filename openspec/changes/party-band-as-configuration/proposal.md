# The party band as configuration

## Why

ClientDetail and ContactDetail drew their warnings with an app-local PartyIndicatorPanel and their call, mail and directions links with ContactLinks. nextcloud-vue's `party-actions` band now reads indicators from an endpoint (tone, period, source, "I have seen this"), says when they cannot be read, offers actions beside them, and turns a phone, a mail address and an address into links. CtiClickToDialButton was never mounted; Call from the agent's own extension becomes an action (decision Q5).

## What changes

- One `party-actions` band before the body on both pages: the warnings, Add a warning (an `open-form` on `partyIndicatorValue` for this party), Call, Call from my extension, Mail and Directions.
- The server orders indicators critical, warning, info, then by label.
- POST /api/cti/click-to-dial dials from the agent's own extension (agentProfile `extension`, schema 1.1.0) when the body names none, and says so in the agent's language when there is none.
- Removed: PartyIndicatorPanel, ContactLinks, CtiClickToDialButton, services/partyIndicators.js, services/contactLinks.js and ctiApi `clickToDial`.

## Needs

nextcloud-vue `party-actions` with `indicatorSource.url`, `errorText`, `indicatorActions` and link filters, and `geo:` as an external target (branch `feature/pl-abstract-pl1-party-actions`); body sections with a widget `type` (branch `feature/pl-abstract-pl1-handoff`).
