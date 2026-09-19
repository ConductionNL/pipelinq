# Design: kcc-agent-panel

## Context

integriq's `kcc-cti-adapter` (proposed; unimplemented on both sides at the
time of writing) defines, and pipelinq here only reads:

- A `CallEvent(kind, callId, callerNumber, caller, openCases[], agentId,
  sourceId, at)` PHP event, `kind` one of `ringing`, `answered`, `ended`,
  `transferred`.
- The same fact published on the CloudEvents fan-out as
  `nl.conduction.integriq.call.<kind>` (integriq's `events-cloudevents`
  REQ-001/REQ-002 machinery: a webhook-style push to any
  `event_subscription` whose `types[]` matches).
- `caller` is the `partij` the configured klantinteracties provider resolved
  from the E.164 caller number, or `null` on no match; `openCases[]` is
  whatever case references that provider's own data already carries. Both
  are read verbatim here; this change adds no lookup of its own inside
  integriq.
- A contact moment is written only when the panel asks
  (`kcc-cti-adapter` REQ-007), through integriq's existing REQ-004 push
  endpoint — not designed here, only used, by whichever consumer wants to
  log a call.

Pipelinq's own `cti-screenpop-adapter` (shipped) is a separate, direct
integration with a per-organisation telephony platform: `CtiController`
verifies a platform-specific webhook, `CtiContactMatcher` resolves a caller
against pipelinq's own `client`/`contact` by phone, and a `ticket` (the
`unify-ticket-supertype` schema, shipped) is created with disposition
workflow. Its frontend surfaces
(`ScreenPopModal.vue`, `NewContactIntakeModal.vue`,
`CtiClickToDialButton.vue`, `CtiDispositionModal.vue`) exist but are wired
into no route — a pre-existing product gap, recorded in
`openspec/specs/cti-screenpop-adapter/spec.md`, that this change does not
fix. Nothing in `kcc-agent-panel` touches `CtiController`, `CtiService`,
`CtiContactMatcher` or the `cti_adapter_config`/`cti_event_log`/
`cti_agent_presence` schemas: different telephony source, different
resolution path, deliberately no shared code.

`client` (`lib/Settings/pipelinq_register.json`) already implements
`https://openregister.app/ns#Customer`; `ticket` (`register.d/
99-unify-ticket-supertype.json`) carries `client`/`contact` FKs and a
`ticketType` discriminator. Both are read here, neither is changed.
dossiq's `brpPerson`/`kvkCompany` implement the sibling
`https://openregister.app/ns#Requester` (`requester-on-the-case`); dossiq's
`InitiatorSection.resolveSource()` already resolves an identifying number to
a row of one of those schemas by searching the register with `_limit: 1`.
This change reads that pattern as precedent, not as shared code: resolving
an identifying number to a semantic-type row is small enough that
duplicating the query in pipelinq is preferable to a new cross-app
dependency, and is flagged in Open Questions below as a candidate for a
shared OpenRegister/nextcloud-vue helper once a third consumer wants it.

ADR-032 kind: **code**. A new page, a webhook consumer, and a resolution
service.

## Goals / Non-goals

**Goals:**

- When a call is routed through integriq's KCC bridge, an agent looking at
  pipelinq's panel sees who is calling (or "unknown caller") within the
  latency integriq's `CallEvent` already carries, without polling.
- The panel shows what is open for that caller: pipelinq's own `ticket`
  rows when the caller matches a `client`/`contact`, and, for any other
  OpenRegister row that implements the requester/customer semantic type and
  matches the caller's identifying number, a link to that row's own app.
- Any app links to the panel with a plain URL. No shared Vue component, no
  iframe, no new runtime dependency between apps.

**Non-goals:**

- Resolving the caller inside integriq. That is `kcc-cti-adapter` D3; this
  change consumes `CallEvent.caller` as given.
- Any change to `cti-screenpop-adapter`'s telephony platforms, its own
  contact matching, or its `ticket`/disposition flow.
- Call control (answer, transfer, click-to-dial) from the panel.
- A generic "find any Requester/Customer row by number" library. Named in
  Open Questions, not built here.

## Decisions

### D1: the panel is a page pipelinq owns, reached by a plain link

A new manifest page, `KccAgentPanel` (working route `/kcc/agent-panel`,
`type: custom`: it renders live call state and a resolved-identity list,
neither a stock index nor a stock detail shape). It carries a pipelinq menu
entry, since pipelinq's own KCC-facing agents use it directly. A consuming
app adds no menu entry of its own; it places a plain hyperlink built with
`generateUrl('/apps/pipelinq/kcc/agent-panel')`, the same cross-app pattern
already in use elsewhere in the fleet (dossiq's `menu-layout.json` documents
several such links, e.g. its own entry pointing at OpenRegister's
`organisation` page). No iframe, no shared component: the link opens
pipelinq, in pipelinq's own chrome.

An optional `?callId=<id>` query parameter focuses one call when a page
links to a specific, already-known call rather than the agent's live queue;
absent, the panel shows the newest active call.

### D2: what the panel shows

For the active call:

- **Caller.** `CallEvent.callerNumber`, and `CallEvent.caller`'s display
  name when integriq resolved a `partij`; otherwise "Unknown caller" — the
  panel never blocks on identity, the same principle `kcc-cti-adapter` D3
  states for the event itself.
- **In pipelinq.** When the caller number matches a `client` or `contact`
  (`phone`, E.164-compared), that record's identity and its open `ticket`
  rows (`status` not in a terminal state, FK `client`/`contact` matching),
  each linking to pipelinq's own `ClientDetail`/`ContactDetail`.
- **Elsewhere.** For every OpenRegister schema implementing
  `https://openregister.app/ns#Requester` or `.../ns#Customer` whose
  identifying-number field matches a number `CallEvent.caller` carries (the
  same identifying numbers integriq's klantinteracties provider already
  resolved against — BSN, KVK, or the phone number itself), a row: display
  name, source app, and a link built the same way as D1's cross-app link,
  to that app's own detail page for the row. dossiq's `ContactDetail` and
  `OrganisationDetail` are the first such targets once `contacts-domain`
  re-links task 4.2 here.
- **openCases.** `CallEvent.openCases[]` rendered as-is (reference and
  source), since resolving what they point to is the owning app's job, not
  the panel's; a case reference with nowhere to click is still information,
  and clicking it is D1's cross-app link once the owning app supplies one.

A caller matching nothing in either section still shows: number, "Unknown
caller," and no items — the honest empty state, not a hidden section.

### D3: how a call reaches the panel

Pipelinq registers one `event_subscription` (integriq's `events-cloudevents`
REQ-001 mechanism, unchanged) for `types: ["nl.conduction.integriq.call.*"]`
with a sink on a new pipelinq controller endpoint,
`POST /api/kcc/call-events`. That endpoint does two things:

1. Mirrors the event into a short-lived pipelinq-owned OpenRegister object,
   `kccCall` (new schema, register `pipelinq`), carrying the same fields as
   `CallEvent` plus the resolved "In pipelinq"/"Elsewhere" matches computed
   at write time. Retention matches integriq's own 30 days on `callEvent`
   (`kcc-cti-adapter` REQ-007): a call is a fact worth keeping only as long
   as integriq itself keeps it.
2. Nothing else. No contact moment is written here — `kcc-cti-adapter`
   REQ-007 is explicit that a contact moment is written only when a
   consumer pushes for it, and this change's panel is display, not
   disposition.

Because `kccCall` is an ordinary OpenRegister object, the panel's live
update needs no bespoke transport: nextcloud-vue's `useLiveUpdates`
(`add-live-updates-plugin`) already delivers `or-object-{uuid}` and
`or-collection-{register}-{schema}` events to a subscribed tab. The panel
subscribes to the `pipelinq-kccCall` collection the way any manifest-driven
index page would, no new consumer-side plumbing invented for this change.

### D4: identity resolution runs once, at mirror time, not per view

Resolving "In pipelinq" and "Elsewhere" matches on every panel render would
repeat the same OpenRegister queries for every open tab watching the same
call. The webhook handler (D3) resolves both once, when it mirrors the
event, and stores the match list on the `kccCall` record. A later manual
refresh (the agent re-runs the search, e.g. after creating a new client
mid-call) is a separate, explicit action, not an automatic re-poll.

### D5: what "linking without embedding" rules out

Explicitly not done, so a future task doesn't reach for it by habit:

- No shared Vue component published from pipelinq for another app to
  import. A component import couples the two apps' build and release
  cadence; a URL does not.
- No iframe. An iframed panel cannot carry Nextcloud's own auth/session
  context cleanly across apps and would need its own postMessage contract
  for the "open X" links in D2 to do anything.
- No new manifest-level cross-app primitive. `generateUrl()` to another
  app's route is already how the fleet links across apps today (D1); this
  change needs nothing new from nextcloud-vue.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Rendering the caller and their items | declarative, `type: custom` page with declarative widgets over `kccCall` and its stored matches | Same primitives every manifest page uses; the live list is the only non-stock part. |
| Mirroring `CallEvent` into `kccCall` | code, a controller endpoint | A webhook consumer is not expressible declaratively. |
| Resolving Requester/Customer matches | code, at mirror time (D4) | A cross-register search by identifying number, same shape as `InitiatorSection.resolveSource()`. |
| Linking to another app's page | declarative, a plain URL in the widget's link config | No code needed once the target route is known. |

## Open Questions

- Should "resolve an identifying number to a Requester/Customer row" become
  a shared OpenRegister or nextcloud-vue helper once a third consumer wants
  it (dossiq's `InitiatorSection` and this change's D4 would both use it)?
  Not decided here; flagged so the second duplication is the one that
  triggers extraction, not the third.
- Whether pipelinq's own `cti-screenpop-adapter` and this panel should ever
  converge onto one surface is explicitly left open (Motivation). Nothing
  in this design forecloses it; nothing in it assumes it either.

## Risks / Trade-offs

- The panel depends on `kcc-cti-adapter` shipping first; until then it has
  no data source and is unreachable in practice, which is why this change
  is spec-only. Both changes can be implemented independently once each
  has been approved.
- `kccCall` duplicates fields integriq already retains on its own
  `callEvent` log for the same 30 days. Accepted: cross-app OpenRegister
  reads work per-app-owned register today, and a 30-day mirror is cheap
  next to a bespoke cross-app read API.
- A caller matching several `client`/`contact` rows (shared office number)
  needs the same "more than one match" handling
  `cti-screenpop-adapter`'s (currently unreached) `ScreenPopModal` already
  designed for. This change shows all matches rather than picking one,
  which sidesteps the ambiguity rather than resolving it silently.
