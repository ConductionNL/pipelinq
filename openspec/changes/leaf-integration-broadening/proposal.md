---
kind: mixed
---

## Why

Pipelinq consumes OpenRegister's app-agnostic integration leaves well on the comms axis — email, calendar, files, and notes widgets are mounted across the CRM detail pages (`client-email`/`client-files`/`client-notes`, `contact-email`/`contact-calendar`/`contact-files`, `lead-email`/`lead-files`/`lead-calendar`/`lead-notes` in `src/manifest.json`), and the decidesk decisions leaf is mounted on leads and tickets (`lead-decisions`, `ticket-decisions`). But the collaboration axis is declared and then silently dropped:

1. **The `lead` schema declares `deck` in its `linkedTypes` (`lib/Settings/pipelinq_register.json` L358–366: `deck, flow, time-tracker, xwiki, email, calendar, forms`) but no deck widget is mounted anywhere in `src/manifest.json`** (zero `"integrationId": "deck"` entries). The LeadDetail `_note` records why: "Generic Deck/Flow/Time/Knowledge/Forms leaves dropped from the sidebar per the audit-only rule" — the leaves were evicted when the sidebar became audit-only, and were never re-mounted as body widgets. Declared-but-unmounted is the worst state: the schema promises a Deck link surface that the page never renders, and a card board is exactly how a deal team tracks the offer checklist on a complex sale. The `ticket` schema (`lib/Settings/register.d/99-unify-ticket-supertype.json`) declares `deck` identically and is identically unmounted.
2. **`forms` is declared on `lead`, `client`, and `ticket` `linkedTypes` and equally unmounted** — no `"integrationId": "forms"` widget exists. A lead is the one record type that routinely *originates* from a form (intake), and the linked form submissions are invisible on the deal.
3. **Talk is absent entirely** — no `talk` in any `linkedTypes`, no talk widget. A deal that is actively worked has a conversation; today that conversation happens in an unlinked Talk room (or worse, off-platform), disconnected from the lead and the client record.
4. **Polls**: deliberately left out — see design D4. Meeting-time finding belongs to the calendar leaf (`calendar-deepening`), and structured option-choosing on deals/tickets is already served by the mounted decidesk decisions leaf (`lead-decisions`, `ticket-decisions`).

## What Changes

Pipelinq-side only — manifest rows plus two `linkedTypes` additions; the leaves themselves are OpenRegister's, and pipelinq builds no collaboration machinery of its own:

1. **Deck widget on LeadDetail** — mount `{ "id": "lead-deck", "type": "integration", "integrationId": "deck", "title": "Board", "icon": "BulletinBoard" }` as a *body* widget (respecting the audit-only-sidebar rule the `_note` records) plus a layout row. This closes the declared-but-unmounted gap for the type the `lead` schema already lists.
2. **Talk rooms on client and lead** — add `"talk"` to the `client` and `lead` schemas' `linkedTypes` (`pipelinq_register.json` L89–96 and L358–366) and mount `client-talk` ("Client room") on ClientDetail and `lead-talk` ("Deal room") on LeadDetail, the same prop-less integration-widget shape every other leaf uses. Per-deal and per-client discussion linked to the record; conversation history stays in Talk, link state stays in the leaf.
3. **Forms widget on LeadDetail** — mount `lead-forms` ("Intake forms") so forms and submissions linked to the deal are visible where the deal is worked. This consumes the NC Forms leaf on an internal detail page; it does **not** implement the separate (draft, unbuilt) `public-intake-forms` capability (embeddable external website forms), which stays untouched.
4. **Declared-vs-mounted conformance** — every `linkedTypes` entry on `client`, `contact`, `lead`, and `ticket` is afterwards either mounted as a widget on the type's detail page or recorded as a deliberate exclusion in the page `_note` (as `flow`/`time-tracker` already are), so the next declared-but-unmounted drift is visible in review instead of silent.

## Capabilities

### New Capabilities

- `collaboration-leaves` — deck, talk, and forms leaf consumption on the CRM detail pages, plus the declared-vs-mounted conformance rule.

### Related change (not duplicated here)

- **`calendar-deepening`** owns everything calendar: the ClientDetail calendar widget, follow-up reminders, timeline merge, and inbound backfill consumption. This change does not touch the calendar leaf, does not add calendar widgets, and its ClientDetail layout row must be rebased under `calendar-deepening`'s `client-calendar` row if that change lands first (see design, Migration Plan).

## Impact

- `src/manifest.json` — four new integration widgets (`lead-deck`, `lead-talk`, `lead-forms`, `client-talk`) + layout rows (additive); `_note` updates recording the remaining deliberate exclusions.
- `lib/Settings/pipelinq_register.json` — `"talk"` appended to `client.linkedTypes` and `lead.linkedTypes` (additive; no property change, no data migration).
- No new pipelinq component, store, controller, service, schema, or route. No Talk/Deck/Forms API calls from pipelinq code — the leaves own all I/O (ADR-022 leaf-first).
- Degrades gracefully: with the Deck, Talk, or Forms app absent, the corresponding widget renders its integration-unavailable state and the page renders normally.
