---
kind: config
depends_on: [openregister-web-push-engine]
---

## Why

When a Contactmoment (an inbound interaction — a call coming in, an email arriving) is registered for a client, the handling agent should be notified with a rich notification carrying a one-click **Open client** button — and that notification must reach the agent even when no Nextcloud tab is open. The dialect now supports this end-to-end (action buttons, relation-resolved deeplinks, the `web-push` background channel, originApp identity) after the `notification-actions-and-web-push` contract and the `openregister-web-push-engine` engine landed. This change is the exemplar consumer: it is the first per-app rule to actually USE those new dialect features, proving the chain end-to-end on pipelinq's `contactmoment` schema.

## What Changes

- Add an `x-openregister-notifications` block to the `contactmoment` schema in `lib/Settings/pipelinq_register.json` (the schema currently has none).
- Declare an `incomingCall` rule (fires on `created` where `channel == telefoon`) and an analogous `incomingEmail` rule (`channel == email`), each notifying the handling `agent`.
- Each rule carries `originApp: "pipelinq"` (drives pipelinq icon/badge + deeplink base), `channels: ["nc-notification", "web-push"]` (rich foreground popup + true background delivery), and an `actions[]` entry whose primary button is an `object-detail` target resolved via the `client` relation — the **Open client** button.
- nl/en i18n on every rule `subject` and every action `label` (ADR-007).
- No PHP, no Vue: this is a pure declarative schema-register JSON patch (`kind: config`). It ships no service class.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `contactmomenten`: adds the requirement that an inbound Contactmoment emits an agent notification carrying an "Open client" action, delivered on both `nc-notification` and `web-push`, with the action deeplinking to the related Client via server-side relation resolution.

## Impact

- File: `lib/Settings/pipelinq_register.json` — `contactmoment` schema gains an `x-openregister-notifications` block (additive; no other schema touched).
- Depends on `openregister-web-push-engine` being merged first — the engine resolves the relation target, composes the originApp icon, and delivers the `web-push` channel. Until then the rule's `nc-notification` channel still works but `web-push` and the relation-resolved action are inert.
- No API, no database, no migration. Existing `contactmoment` seed objects are unaffected (the rule is behavioural, not data).
