---
kind: code
depends_on: []
chain:
  - kcc-schemaorg-consolidation      # this spec (code) — pipelinq register becomes the canonical schema.org KCC model
  - kcc-task-enum-migration          # (code) — anglicise task enums + Repair step rewriting the 4 Dutch-valued rows
  - kcc-contactmoment-slug-rename    # (code) — contactmoment → contactMoment across register, controller, specs, tests
  - kcc-dutch-mapping-layer          # (code) — Dutch/ZGW field names served from a mapping layer in controllers
  - kcc-procest-retirement           # (code) — delete procest's duplicate KCC schemas, services, controllers, tests
---

## Why

Pipelinq's ADR-001 ("International First, Dutch API Mapping Layer", accepted 2026-03-19) is unambiguous: data is stored in schema.org vocabulary with English property names, and Dutch government API shapes (VNG Klantinteracties, ZGW) are served by a **separate mapping layer** that "MUST NOT pollute the core data model".

Procest violates this. It carries a parallel, Dutch-named contact-centre (KCC) data model in `lib/Settings/register.d/30-kcc.json` and `40-kcc-werkplek.json` — `Contactmoment`, `Belplan`, `Doorverbinding`, `Klant Sentiment`, `Specialist Beschikbaarheid`, `KCC Quick Action`, `KCC Agent`, `Callback Request`, `Routing Rule` — plus a Dutch-fielded `complaint` (`klachtnummer`, `klager`, `onderwerp`, `ontvangstkanaal`, …) in `procest_register.json`. None of these carry an `x-schema-org` marker, so none of them are reachable by OpenRegister's JSON-LD resolver.

Two things make this a duplication rather than a relocation:

1. **The KCC surface already lives in pipelinq.** Pipelinq's register already declares `contactmoment` (`schema:CommunicateAction`), `complaint`, `task` (`schema:Action`), `queue` (`schema:ItemList`), `skill` (`schema:DefinedTerm`) and `agentProfile` (`schema:Person`) — all English-propertied — alongside `ctiAdapterConfig` / `ctiEventLog` / `ctiAgentPresence` (`70-cti.json`) and the Berichtenbox schemas (`80-berichtenbox.json`). The controllers exist too: `ContactmomentController`, `KccWerkplekController`, `CallbackController`, `RoutingController`, `CtiController`. So do the capability specs: `contactmomenten`, `kcc-werkplek`, `klachtenregistratie`, `callback-management`, `queue-management`, `skill-routing`, `omnichannel-registratie`.
2. **The slug `contactmoment` is declared in both registers.** Procest's Dutch `contactmoment` and pipelinq's English `contactmoment` are two different schemas sharing one slug — a cross-app slug collision waiting to bite.

Doing this now is cheap: every Dutch-named schema is **empty (0 rows)**. Only `Routing Rule` (3), `KCC Agent` (3) and `Callback Request` (1) hold rows — 7 demo/seed rows total, all reseedable. There is no data migration.

## What Changes

This is **spec 1 of a 5-spec chain** (ADR-032). It is `kind: config` — it touches only declarative schema-register JSON in pipelinq. It **adds and re-types; it deletes nothing**, so it is safe to merge on its own (expand-then-contract).

Two ratified requirements — **English slugs** and **English enum values** — are *specified* here and *implemented* by chain specs 2 and 3, because each carries a data-touching Repair step and consumer updates that make it `code`, not `config` (see design.md, Decision 1). Both are cheap: measured against the live DB, `contactmoment` holds **0 rows** (rename is metadata-only) and the entire enum-value migration is **4 rows in one table**.

- Add a new pipelinq register fragment `lib/Settings/register.d/71-kcc-contactcentre.json` declaring the contact-centre schemas pipelinq genuinely lacks, all schema.org-typed with English property names:
  - `callPlan` (`schema:Schedule`) — was `Belplan`
  - `callTransfer` (`schema:TransferAction`) — was `Doorverbinding`
  - `contactSentiment` (`schema:Rating`) — was `Klant Sentiment`
  - `quickAction` (`schema:Action`) — was `KCC Quick Action`
  - `routingRule` — was `Routing Rule`; config-shaped, deliberately carries **no** `x-schema-org`
  - `complaintCategory`, `complaintDisposition`, `hearing` — the three satellites procest's `complaint` depends on
- Extend pipelinq's existing schemas rather than duplicating them:
  - `contactmoment` — absorbs procest's `contactmoment` and `customerContact` (identification, transcript, transfer, related-cases fields)
  - `complaint` — absorbs procest's Awb-lifecycle complaint (English fields, `x-openregister-lifecycle`)
  - `task` — absorbs `Callback Request` (`task.type: terugbelverzoek` already exists for exactly this)
  - `agentProfile` — absorbs `KCC Agent` **and** `Specialist Beschikbaarheid` (both are agent availability/skills/workload)
- **BREAKING (JSON-LD output):** re-type `complaint` from `x-schema-org: schema:Message` to `schema:CommunicateAction`. The current marker contradicts both its own description and the `klachtenregistratie` spec, which claim `schema:ComplainAction` — a type schema.org does not define. A complaint is an inbound communication with a subject and a handler.
- **Anglicise every enum value that sits on a zero-row schema** — free, so done here: `contactmoment.channel` (`telefoon`/`balie`/`brief` → `phone`/`counter`/`letter`), `contactmoment.outcome` (`afgehandeld`/`doorverbonden`/`terugbelverzoek` → `resolved`/`transferred`/`callback_requested`), `complaint.status`/`priority`/`channel`. All new schemas use English enum values throughout.
- Seed a realistic municipal contact-centre demo dataset in pipelinq, replacing procest's 7 demo rows.

Explicitly **not** in this change — each is a chain spec because each touches code, not just JSON:

- **`kcc-task-enum-migration` (`kind: code`)** — anglicise `task.type` (`terugbelverzoek`→`callback_request`), `task.status` and `task.priority` (`laag`/`normaal`/`hoog`→`low`/`normal`/`high`), plus a Repair step rewriting the **4 live rows** that carry Dutch values. Schema enum + row rewrite must land atomically or those rows fall outside the declared enum.
- **`kcc-contactmoment-slug-rename` (`kind: code`)** — `contactmoment` → `contactMoment` across the register, the `70-cti.json` patch, `ContactmomentController`, the Vue stores, the manifest, four specs, tests and the postman collections. Zero objects to migrate; the cost is entirely reference updates, which must land atomically with the register edit or the app breaks.
- **`kcc-dutch-mapping-layer` (`kind: code`)** — Dutch/ZGW field names served from a mapping layer built on OpenRegister's existing `MappingService`.
- **`kcc-procest-retirement` (`kind: code`)** — deletion of procest's `30-kcc.json`, `40-kcc-werkplek.json`, `kcc_werkplek_seed_data.json`, the complaint family in `procest_register.json`, and the ~40 PHP/Vue/test/postman/n8n sites that reference them.

## Capabilities

### New Capabilities

- `kcc-schemaorg-model`: Pipelinq's register is the single canonical, schema.org-typed, English-propertied data model for the contact centre (contact moments, complaints, callbacks, call plans, transfers, sentiment, quick actions, routing rules, agent availability). Defines which schema.org type each contact-centre concept carries, that property names are English, that Dutch/ZGW naming is a derived mapping and never a stored field, and that no second contact-centre model may be declared in another app's register.

### Modified Capabilities

- `klachtenregistratie`: the `complaint` schema's schema.org type changes from the (non-existent) `schema:ComplainAction` / actually-declared `schema:Message` to `schema:CommunicateAction`, and the schema gains the Awb chapter-9 lifecycle, deadline and hearing-waiver fields — with English property names — that currently live only in procest.

## Impact

- **pipelinq (config, this change):** `lib/Settings/pipelinq_register.json` (`contactmoment`, `complaint`, `task`, `agentProfile`); new `lib/Settings/register.d/71-kcc-contactcentre.json`; `lib/Settings/demo_seed_data.json`. Register-fragment unit tests.
- **pipelinq (code, chain specs 2 & 3):** a Repair step for the 4-row enum rewrite; `ContactmomentController` + Vue stores + manifest for the slug rename.
- **pipelinq (code, chain spec 4):** `ContactmomentController`, `KccWerkplekController`, `CallbackController`, `ZgwNotificationController` — the Dutch/ZGW mapping seam, built on OpenRegister's existing `MappingService`.
- **procest (code, chain spec 5):** removal of the duplicate model and its ~40 referencing sites (inventoried in design.md).
- **JSON-LD consumers:** anything reading `@type` for a pipelinq `complaint` sees `CommunicateAction` instead of `Message`. No pipelinq code branches on this today.
- **Data migration is tiny and measured, not assumed.** Against the live DB: `contactmoment` **0 rows**, `complaint` **0 rows**, `ctiAgentPresence` **0 rows**, every Dutch procest schema **0 rows**. `task` holds 10 pipelinq rows of which exactly **4** carry Dutch enum values; `agentProfile` holds **5** rows and `queue` **153**, so the merges into them MUST be strictly additive. Full numbers and the migration design are in design.md.
