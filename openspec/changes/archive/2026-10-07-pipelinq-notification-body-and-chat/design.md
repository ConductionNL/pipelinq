## Context

`pipelinq-open-client-on-contactmoment` (DONE) added an `x-openregister-notifications` block to the `contactmoment` schema with `incomingCall` (`channel == telefoon`) and `incomingEmail` (`channel == email`) rules. Each rule has a single `subject` (an i18n string). At dispatch the `openregister-web-push-engine` composes the rich notification from that `subject` — and uses it for BOTH the notification title and the body. The result is a notification whose two lines are identical ("Incoming call from {{client}}"), wasting the body line that web-push and the Nextcloud notification UI both render.

`openregister-notification-body` (the engine dependency of this change) adds a distinct optional `message` field to a rule, with the same i18n shape as `subject`. The engine renders `subject` as the notification TITLE and `message` as the BODY, falling back to `subject` for the body when `message` is absent (so existing rules without `message` keep working). This change is the pipelinq consumer of that model.

The `contactmoment` schema's `channel` enum is `telefoon, email, balie, chat, social, brief`. `chat` is a defined inbound channel with no notification rule today; live chat (web widget, Talk handoff, social DM bridged to chat) is an inbound interaction the handling agent wants surfaced exactly like a call or email.

## Goals / Non-Goals

**Goals:**
- Add an i18n `message` (BODY) field to `incomingCall` and `incomingEmail` so the title carries the event and the body carries context + a one-tap invite. Leave `subject` (TITLE) unchanged.
- Add an `incomingChat` rule (`created` + `channel == chat`) mirroring `incomingEmail`: same `originApp`, channels, recipients, and the relation-resolved "Open client" action, with chat-appropriate `subject` + `message`.
- nl/en i18n on every `subject` and `message`.

**Non-Goals:**
- No engine work — title/body rendering and the `message` fallback all live in `openregister-notification-body`.
- No PHP, no Vue, no new schema, no API.
- Not changing the existing `subject` strings, recipients, channels, `originApp`, or actions of `incomingCall`/`incomingEmail`.
- Not adding notification rules for the remaining channels (`balie`, `social`, `brief`) — out of scope.

## Decisions

### Title vs body split

`subject` stays the event headline ("Incoming call from {{client}}"); `message` becomes the context + call-to-action ("{{client}} is a contact in Pipelinq. Open it in Nextcloud?"). The body deliberately states what the agent can DO (open the linked client) rather than repeating the event, because the primary "Open client" action and the body should reinforce the same next step. Wording is varied per channel ("Open it" / "Open the email" / "Open the conversation") so the body reads naturally for each interaction kind.

### `message` shape mirrors `subject`

`message` is an i18n object `{ nl, en }` — the exact same shape as `subject` — per the `openregister-notification-body` model. This keeps the dialect uniform and lets the engine treat the two fields identically apart from their title/body roles.

### `incomingChat` mirrors `incomingEmail`

The new rule is a structural copy of `incomingEmail` with `value: "chat"` and chat wording. Same `trigger.type: created`, same single-equals filter on `channel`, same `originApp: "pipelinq"`, same `channels: ["nc-notification", "web-push"]`, same `recipients` (agent + `sales` fallback), and the same relation-resolved `object-detail` "Open client" action targeting the `client` relation. This keeps the three inbound-channel rules consistent and matches the contract's filter grammar (single `{ field, operator: equals, value }`), per the precedent change's "two rules, not one OR-filter" decision — now three.

### Back-compat of the engine fallback

Because `openregister-notification-body` falls back to `subject` for the body when `message` is absent, the rules degrade gracefully if this change merges before the engine: the engine simply ignores the unknown `message` field and behaves exactly as today (title == body). `depends_on` still enforces ordering so the body model is honoured.

## Risks / Trade-offs

- **Depends on the engine recognising `message`.** Until `openregister-notification-body` ships, `message` is inert (the engine still uses `subject` for the body). The `nc-notification` and `web-push` channels keep delivering; only the distinct-body improvement is deferred. `depends_on` enforces the order.
- **`{{client}}` interpolation in the body** renders the raw `client` value (a UUID) unless the engine resolves the relation's display label — the same engine display concern noted in the precedent change. Body wording is kept generic so it reads acceptably either way.
- **`chat` channel coverage** assumes inbound chat sets `channel == "chat"` on the Contactmoment. Chat interactions routed through other channels (e.g. logged as `social`) will not fire `incomingChat`; this is intentional scoping for v1.

## Seed Data

N/A — existing `contactmoment` seed objects suffice. This change is behavioural (notification rule metadata), not data: it adds no new objects and modifies no existing seed. No `chat`-channel seed is required to validate the change.

## Migration Plan

- **Deploy**: merge the additive `message` fields + the `incomingChat` rule onto the `contactmoment` schema; OpenRegister re-imports the register on app install/update. No data migration, no API change.
- **Chain ordering**: this change `depends_on: [openregister-notification-body]`; do not merge ahead of the engine change that recognises `message`.
- **Back-compat**: purely additive — `subject`, recipients, channels, `originApp`, and actions of the existing rules are untouched; the new `message` field and the new rule add behaviour without removing any.
- **Rollback**: remove the `message` fields and the `incomingChat` rule and re-import; `incomingCall`/`incomingEmail` revert to title-only and no downstream cleanup is needed.
