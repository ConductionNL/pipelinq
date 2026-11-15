# Proposal: unify-ticket-supertype

## Problem

Pipelinq models three overlapping "inbound customer matter" concepts as three
separate OpenRegister schemas, each with its own navigation entry, index page,
detail page, controllers and reporting surface:

- **`request`** (schema:Demand) — a case/demand with a pipeline, stage, queue and
  case reference. Nav label: **Tickets** (route `Requests`).
- **`complaint`** (schema:Message) — a klacht with an SLA deadline, resolution
  and a category enum. Nav label: **Complaints**.
- **`contactmoment`** (schema:CommunicateAction) — a single logged interaction
  (call/email/balie/chat/…) that already carries a `request` UUID pointing at
  its parent case. Nav label: **Contactmomenten**.

The three share the same spine — title, description, client, contact, assignee,
status, priority, channel, timestamps — and differ only in a handful of
type-specific fields. The result is triplicated UI, triplicated reporting, three
status vocabularies that don't line up, and a confusing operator experience: the
same citizen interaction can plausibly be filed as a ticket, a complaint or a
contactmoment, and a user must pick a top-level menu before they can even start.
The product intent (confirmed with the product owner on 2026-07-11) is that
pipelinq **abstracts complaints, tickets and contactmomenten into a single
ticket object**; the three separate nav entries are the visible symptom that the
abstraction was never built.

## Solution

Introduce a single **`ticket`** supertype schema with a `ticketType`
discriminator (`request` | `complaint` | `contactmoment`), migrate every existing
`request`, `complaint` and `contactmoment` object into it (preserving all field
data and all cross-object links), and collapse the three UI surfaces into one
type-aware **Tickets** workspace with a `ticketType` facet.

`task` is **out of scope** — a task is a to-do item worked to completion, not an
inbound matter; it keeps its own schema and only has its `requestId` foreign key
remapped to the new ticket UUID.

The change is delivered in **safe, additive phases** so no step performs a
big-bang mutation of live data:

1. **Phase 0 — additive schema.** Register the `ticket` schema alongside the
   existing three. No behaviour change; nothing reads it yet.
2. **Phase 1 — migration.** An idempotent repair step copies each `request` /
   `complaint` / `contactmoment` object into a `ticket`, records an
   old-UUID → new-UUID map, remaps every reference that pointed at the old
   objects (`contactmoment.request` → `ticket.parentTicket`, `task.requestId`,
   and the OpenRegister link tables for mail/deck/calendar/files), then verifies
   counts. The old objects remain readable throughout.
3. **Phase 2 — UI + write cutover.** The three nav entries collapse to one
   **Tickets** index (with a `ticketType` facet), one type-aware detail page, and
   the contactmomenten reporting page reads `ticket where ticketType=contactmoment`.
   Every surface that *creates* one of the old types (CTI screen-pop, omnichannel
   registration, SLA engine, notifications, the ZGW bridge) writes `ticket`.
4. **Phase 3 — retire.** After a soak window with the migration verified in
   production, the old `request` / `complaint` / `contactmoment` schemas are
   marked deprecated and removed from the register, and their config keys drop.

## Scope

**In scope:** the `ticket` schema and its `ticketType` discriminator; the
migration repair step and reference remapping; the unified Tickets index +
detail + facet; the contactmomenten reporting cutover; rewiring the
create-side surfaces (CTI, omnichannel registration, SLA engine, notifications,
ZGW bridge) to `ticket`; SchemaMapService / SettingsService config keys; specs
and docs.

**Out of scope:** the `task` schema (only its FK is remapped); the `lead`
pipeline; any change to VNG Klantinteracties field semantics beyond folding them
under one schema (per-type `x-schema-org` markers are preserved so Contactmoment
stays a CommunicateAction, Complaint a Message, Request a Demand).

## Risks

- **Link-table remapping is the highest-risk step.** Mail, deck, calendar and
  file links in OpenRegister key on the object UUID; the migration must re-link
  them to the new ticket UUID or attachments/threads orphan. Phase 1 treats this
  as a first-class, separately-verified sub-step with a dry-run count.
- **Status/enum reconciliation is lossy if done naively.** The three status
  enums and the contactmoment `outcome` vocabulary must map into one superset
  without collapsing distinct states (see design.md).
- **External slug consumers.** The ZGW API bridge, omnichannel-registratie and
  CTI adapters reference the `contactmoment` slug; they must be cut over in
  Phase 2 before the slug is retired in Phase 3.
- **Live shared dev instance.** The migration must be idempotent and run behind a
  dry-run/verify gate; it must never be executed with a destructive `force`
  re-import (which can drop schema linkage).
