---
kind: config
depends_on: [openregister-web-push-engine]
---

## Why

The single most-wanted fleet notification pattern (fleet-notification-plan.md, pipelinq/procest/openconnector) is: *"an incoming call or email pops a rich notification with an 'Open client' button — even when the browser is in the background."* The `notification-actions-and-web-push` contract (hydra) defined the dialect to express this and `openregister-web-push-engine` implements the delivery. This change is the exemplar consumer: it declares the rule on pipelinq's `contactmoment` schema so an inbound interaction notifies the handling agent with a one-tap deeplink to the linked client.

## What Changes

Adds an `x-openregister-notifications` block to the `contactmoment` schema in `lib/Settings/pipelinq_register.json` (it currently has none) with two rules:

- `incomingCall` — `created` + `channel == "telefoon"`
- `incomingEmail` — `created` + `channel == "email"`

Each rule:
- declares `originApp: "pipelinq"` (so the notification shows pipelinq's hex icon),
- delivers on `["nc-notification", "web-push"]` (rich background notification),
- notifies the handling `agent` plus the `sales` group as a fallback (so an unrouted/system-created inbound interaction still reaches the team — the dialect unions recipients),
- carries a primary **"Open client"** action whose `target` is an `object-detail` resolved server-side through the `client` relation.

Purely a declarative schema-register JSON patch (`kind: config`, ADR-031). No PHP, no Vue, no new schema, no API.

## Capabilities

### Modified Capabilities

- `contactmomenten` — the contact-interaction entity gains inbound-interaction notifications with a relation-resolved "Open client" action.

## Impact

- **Schema register**: `lib/Settings/pipelinq_register.json` (`contactmoment` schema) — additive, back-compatible.
- **Depends on** `openregister-web-push-engine` (the engine that validates `web-push`/`actions`/`originApp` and resolves the relation target). Must not merge ahead of it — until then the current validator would reject `web-push`.
- No data migration; OpenRegister re-imports the register on app install/update.
