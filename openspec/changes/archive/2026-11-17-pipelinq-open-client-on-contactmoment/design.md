## Context

The `notification-actions-and-web-push` contract (hydra, DONE) extended the `x-openregister-notifications` dialect with `actions[]` (4 target kinds incl. relation-resolved `object-detail`, cap 2), `originApp` identity, and the `web-push` background channel. The `openregister-web-push-engine` change (DONE) implements that contract: it validates the new fields, resolves relation targets server-side at dispatch, composes the originApp-keyed hex icon, and delivers `web-push` via VAPID + aes128gcm + a Service Worker.

This change is the TAIL of that 3-change chain (ADR-032): the exemplar consumer. The pipelinq `contactmoment` schema (`lib/Settings/pipelinq_register.json`, slug `contactmoment`) records inbound interactions. It currently has NO `x-openregister-notifications` block (its sibling schemas `lead` and `task` already do). This change adds one, declaring the headline fleet ask: "incoming call/email → rich notification with an *Open client* button, even in the background."

## Goals / Non-Goals

**Goals:**
- Add `incomingCall` (`created` + `channel == telefoon`) and `incomingEmail` (`created` + `channel == email`) rules to the `contactmoment` schema.
- Each rule notifies the handling `agent` (+ `sales` fallback), carries `originApp: "pipelinq"`, delivers on `["nc-notification", "web-push"]`, and exposes a primary "Open client" action targeting the related Client via the relation-resolved `object-detail` kind.
- nl/en i18n on every subject and action label.

**Non-Goals:**
- No engine work — relation resolution, icon compositing, and web-push delivery all live in `openregister-web-push-engine`.
- No PHP, no Vue, no new schema, no API.
- Not modifying the existing `lead`/`task` notification blocks.

## Decisions

### Declarative vs imperative (ADR-031)

This change is **100% declarative** — it is a patch to the `x-openregister-notifications` block in the schema-register JSON (`lib/Settings/pipelinq_register.json`). This is the default and correct path under ADR-031: business behaviour expressed as schema metadata, read by the existing OpenRegister validator + dispatcher. There is no service class, no controller, no background job in this change. All imperative work (relation resolution, VAPID/aes128gcm, icon raster) is the engine's, pre-justified in the engine change's design under ADR-031's external-integration / cryptography / scheduled-delivery exceptions.

### Two rules, not one OR-filter

`incomingCall` and `incomingEmail` are kept as two distinct rules rather than a single rule with an OR-filter on `channel`. The existing pipelinq dialect rules (`lead`, `task`) each express one trigger condition, and the contract's `trigger.filter` uses a single `{ field, operator, value }` shape (equals), with no documented OR operator. Two rules keep each subject channel-appropriate ("Incoming call from …" vs "Incoming email from …") and match the contract's filter grammar exactly.

### Filter syntax

The register's existing `created`-trigger rules carry no filter (they fire on every create). The contract defines a filter as `trigger.filter: { "field": ..., "operator": "equals", "value": ... }`. This change uses that exact shape — `field: "channel"`, `operator: "equals"`, `value: "telefoon"` / `"email"`. The engine's `created`-trigger filter evaluation (`createdFilterMatches`) was added in the engine change to honour this shape.

### "Open client" target

The action `target` is `{ "kind": "object-detail", "object": { "kind": "relation", "field": "client" } }` — exactly the contract's relation-resolved `object-detail` shape. The rule fires on the Contactmoment; the engine resolves the linked Client's register/schema/uuid server-side (through OR RBAC) and builds the deeplink. The client UUID is never trusted from the wire. One action per rule (well within the cap of 2).

### Recipient

`recipients: [{ "kind": "field", "field": "agent" }, { "kind": "groups", "groups": ["sales"] }]` — the Contactmoment's handling `agent` (a Nextcloud user UID) plus the `sales` group as a fallback. The dialect **unions** recipients (there is no conditional agent-else-team semantics in the contract), so a routed call notifies both the agent and the sales group — which also gives the team visibility — while an unrouted call (empty `agent`) still reaches the team and is not silently dropped. True conditional fallback (notify the group only when no agent resolved) would require an engine enhancement and is out of scope here.

## Risks / Trade-offs

- **Depends on the engine being merged first.** Until `openregister-web-push-engine` ships, the `web-push` channel and the relation-resolved action are inert; the `nc-notification` channel still delivers (the action would degrade to the implicit "View"). `depends_on` enforces ordering in the chain.
- **Relation resolution requires `client` to be populated.** `client` is optional on the Contactmoment. If absent, the engine cannot resolve the "Open client" deeplink. The action should degrade gracefully (engine responsibility); the rule still notifies of the inbound interaction.
- **`agent` may be empty.** `agent` is auto-set to the current user on create, but a system/integration-created Contactmoment (CTI, email-to-lead) may lack it. Resolved: the `sales` group is added as a fallback recipient so these unrouted inbound records still reach the team. Trade-off accepted for v1: because the dialect unions recipients, routed calls also notify the sales group (team visibility), rather than a strict agent-else-team fallback (which would need an engine enhancement).
- **Subject interpolation of `{{client}}`** renders the raw `client` value (a UUID) unless the engine resolves the relation's display label. This is an engine display concern; the subject wording is kept generic ("Incoming call from {{client}}") and can be refined once the engine's interpolation behaviour for relations is confirmed.

## Seed Data

N/A — existing `contactmoment` seed objects suffice (`contactmoment-rapportage-seed-1..N` already exist in the register, including `telefoon` and `email` channel examples). This change is behavioural (a notification rule), not data: it adds no new objects and modifies no existing seed.

## Migration Plan

- **Deploy**: merge the additive `x-openregister-notifications` block onto the `contactmoment` schema; OpenRegister re-imports the register on app install/update. No data migration, no API change.
- **Chain ordering**: this change `depends_on: [openregister-web-push-engine]`; do not merge ahead of the engine.
- **Back-compat**: purely additive — no other schema or existing rule is touched. The `contactmoment` schema had no notification block, so nothing regresses.
- **Rollback**: delete the `x-openregister-notifications` block from the `contactmoment` schema and re-import; no downstream cleanup needed.
