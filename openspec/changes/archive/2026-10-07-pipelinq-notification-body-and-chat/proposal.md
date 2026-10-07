---
kind: config
depends_on: [openregister-notification-body]
---

## Why

The `incomingCall` / `incomingEmail` rules added by `pipelinq-open-client-on-contactmoment` carry only a `subject`. The engine fills both the notification TITLE and the notification BODY from that single `subject` string, so the rich notification reads "Incoming call from {{client}}" on both lines — the body adds nothing. The `openregister-notification-body` change introduces a distinct `message` field (the BODY) alongside `subject` (the TITLE), so a rule can show the event in the title and the context + call-to-action in the body. This change adopts that model on pipelinq's `contactmoment` rules and closes the remaining inbound-channel gap by adding a `chat` rule.

## What Changes

Patches the `x-openregister-notifications` block on the `contactmoment` schema in `lib/Settings/pipelinq_register.json`:

- Adds a `message` (BODY) i18n field to the existing `incomingCall` and `incomingEmail` rules, so the title states the event and the body adds context + an "Open it in Nextcloud?" invite. `subject` (TITLE) is unchanged.
- Adds a new `incomingChat` rule — `created` + `channel == "chat"` — mirroring `incomingEmail` (same `originApp: "pipelinq"`, `channels: ["nc-notification", "web-push"]`, agent + `sales`-group recipients, and the relation-resolved "Open client" action) with chat-appropriate `subject` and `message`.

Purely a declarative schema-register JSON patch (`kind: config`, ADR-031). No PHP, no Vue, no new schema, no API.

## Capabilities

### Modified Capabilities

- `contactmomenten` — inbound-interaction notifications gain a distinct body (`message`) and extend to the `chat` channel.

## Impact

- **Schema register**: `lib/Settings/pipelinq_register.json` (`contactmoment` schema) — additive, back-compatible.
- **Depends on** `openregister-notification-body` (the engine change that recognises the `message` field and renders it as the notification body, falling back to `subject` when absent). Must not merge ahead of it — until then the validator ignores `message` (graceful: title-only as today) but the body model is inert.
- No data migration; OpenRegister re-imports the register on app install/update.
