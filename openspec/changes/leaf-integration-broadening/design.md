# Design — leaf-integration-broadening

## Context

OpenRegister ships app-agnostic integration leaves (email, calendar, contacts, files, talk, deck, forms, polls, notes, time-tracker, xwiki, flow, …) that apps consume two ways: schema-level `linkedTypes` (declares which leaf types a record type links to) and manifest integration widgets (`{ "type": "integration", "integrationId": "…" }`) that render the leaf's card on a detail page.

Pipelinq's current consumption, verified in-repo:

| Schema (`pipelinq_register.json` / `register.d/99-unify-ticket-supertype.json`) | `linkedTypes` declared | Widgets mounted on the detail page |
|---|---|---|
| `client` (L89–96) | flow, time-tracker, xwiki, email, calendar, forms | email, files, notes (`client-email`, `client-files`, `client-notes`) — calendar arrives via `calendar-deepening` |
| `contact` (L195–198) | email, calendar | email, calendar, files (`contact-email`, `contact-calendar`, `contact-files`) |
| `lead` (L358–366) | **deck**, flow, time-tracker, xwiki, email, calendar, **forms** | email, files, calendar, notes, decidesk-decisions (`lead-email`, `lead-files`, `lead-calendar`, `lead-notes`, `lead-decisions`) |
| `ticket` (99-unify fragment) | **deck**, flow, time-tracker, xwiki, email, calendar, **forms** | decidesk-decisions (`ticket-decisions`) |

Bold = declared but mounted nowhere (`grep '"integrationId"' src/manifest.json` returns zero `deck`, `forms`, `talk`, or `polls` entries). The LeadDetail `_note` explains the history: "Generic Deck/Flow/Time/Knowledge/Forms leaves dropped from the sidebar per the audit-only rule" — the audit-only-sidebar decision evicted them and no body-widget re-mount followed. `talk` appears nowhere at all (neither `linkedTypes` nor manifest).

## Goals / Non-Goals

**Goals:**
- Close the deck declared-but-unmounted gap on LeadDetail.
- Add per-record Talk rooms on client (account discussion) and lead (deal room): `linkedTypes` + widget.
- Surface linked forms/submissions on LeadDetail (intake context).
- A conformance rule so declared leaf types can no longer silently diverge from mounted widgets.

**Non-Goals:**
- No calendar work of any kind — `calendar-deepening` owns that axis (ClientDetail widget, reminders, timeline, backfill). This change must not re-spec or collide with it.
- No polls (D4), no flow/time-tracker/xwiki widget mounts (they keep their recorded-exclusion status; xwiki already has its own knowledge-base page mount, `xwiki-knowledge`).
- No ticket-page deck/forms mounts in this change: the ticket support-board workflow is unproven and TicketDetail is mid-redesign under `unify-ticket-supertype` follow-ups; the ticket schema's declarations become *recorded* exclusions instead (D3/R4).
- No pipelinq-local Talk/Deck/Forms code: no API client, no store, no component, no schema. The leaf owns creation, linking, unlinking, and rendering.
- No talk on `contact` — a conversation belongs to the deal or the account, not to a person card; contact's `linkedTypes` stays `email, calendar`.

## Decisions

### D1 — Widgets are body rows; the audit-only sidebar rule stands

All four new widgets are `config.widgets` entries with layout rows, exactly the shape of the existing leaf mounts (`lead-calendar`, `client-email`). Nothing is added to any `sidebar.tabs` — the sidebar keeps only the audit tab, per the rule the LeadDetail `_note` records. This is the correct re-mount the original eviction never got.

Placement (appended below current content; grid is 12 wide):
- LeadDetail (current bottom: `lead-decisions` at gridY 19, height 4): `lead-talk` (0, 23, 8×4), `lead-deck` (8, 23, 4×4), `lead-forms` (0, 27, 12×4).
- ClientDetail (current bottom: `client-notes` at gridY 23, height 4): `client-talk` (0, 27, 12×4).

### D2 — Talk needs both a `linkedTypes` entry and a widget; deck and forms only the widget

`deck` and `forms` are already declared on the schemas, so their fix is manifest-only. `talk` is new on both axes: append `"talk"` to `client.linkedTypes` and `lead.linkedTypes` in `pipelinq_register.json` so the leaf's link surfaces recognise the types, then mount the widgets. Both edits are additive; register re-import on app update deploys them (an annotation-only change never deploys by itself — the register version must bump so the import runs).

### D3 — Conformance is a review rule enforced by a test, not a runtime check

The declared-vs-mounted invariant (every `linkedTypes` entry mounted or recorded as an exclusion in the page `_note`) is asserted by a unit test that parses `pipelinq_register.json` + `register.d/*.json` and `src/manifest.json` and fails on an undeclared divergence. Runtime enforcement would be dead weight — the drift only ever happens at authoring time. The recorded exclusions after this change: `flow`, `time-tracker` (all types), `xwiki` (detail pages — mounted app-level on the knowledge page instead), and `deck`/`forms` on `ticket` (deferred, see Non-Goals).

### D4 — Polls: left out, with the reasoning on record

Two candidate uses were weighed. (a) Meeting-time finding on a deal — that is scheduling, and the calendar leaf (plus `calendar-deepening`'s follow-up machinery) is the committed answer; mounting a second scheduling-ish surface splits the workflow. (b) Structured option-choosing ("which proposal variant do we send?") — pipelinq already mounts the decidesk decisions leaf on exactly the two types where that happens (`lead-decisions`, `ticket-decisions`), and a poll is a strictly weaker primitive than a decision with an audit trail. No remaining sales/support scenario justifies the surface; revisit only if a concrete user request lands.

## Risks / Trade-offs

- **R1 — Layout collision with `calendar-deepening`**: both changes append rows to ClientDetail. Whichever merges second rebases its gridY values under the other's rows. Mechanical, but must be checked at merge time (prose layout intent is the half of the diff git cannot check).
- **R2 — Page weight**: LeadDetail grows to 9 leaf/section widgets. The leaves lazy-load and render collapsed empty states, and the deal page is the app's working surface — accepted. If it proves heavy, demotion to a tab is a follow-up, not a blocker here.
- **R3 — Leaf availability**: Deck/Talk/Forms are optional NC apps. The integration widget's built-in unavailable state covers absence; pipelinq adds no `requiresApp` gating of its own (consistent with the existing email/calendar mounts).
- **R4 — Ticket declarations stay unmounted**: deferring ticket deck/forms leaves the ticket schema still declaring more than TicketDetail mounts. Mitigated by D3: the exclusion is now *recorded and tested* rather than silent, which is the actual bug this change fixes.

## Seed Data

None required. Leaf link state lives in OpenRegister's leaf storage, not in pipelinq objects; dev verification uses the widgets' inline create/link flows on existing seeded leads/clients.

## Migration Plan

- `src/manifest.json` widgets + layout rows: additive, deploys with the frontend build; rollback = revert the rows.
- `pipelinq_register.json` `linkedTypes` additions: additive; bump the `lead` and `client` schema `version` patch numbers so the register import applies them (version unchanged ⇒ import no-ops).
- Order relative to `calendar-deepening`: either order works; the second change to land rebases its ClientDetail layout rows (R1).
- No data migration — no property, slug, or value changes anywhere.
