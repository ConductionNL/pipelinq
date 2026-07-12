# Design: KCC schema.org consolidation

## Context

### The rule we are conforming to

Pipelinq **ADR-001** ("International First, Dutch API Mapping Layer", accepted 2026-03-19) already decides this:

> Dutch government API endpoints (Klantinteracties, Verzoeken) MUST be implemented as a **separate mapping layer** … The mapping layer MUST NOT pollute the core data model — Dutch-specific fields are derived/computed, not stored.
> Spec authors MUST use schema.org/vCard property names in requirements (e.g. `fn` not `naam`).

This change is therefore not a new architectural decision. It is an **ADR-001 conformance change** against a violation that landed in the wrong app.

### What actually exists (verified against HEAD, both worktrees)

Procest owns a Dutch contact-centre model:

| File | Declares |
|---|---|
| `procest/lib/Settings/register.d/30-kcc.json` | `customerContact`, `routingRule`, `kccAgent`, `callbackRequest` |
| `procest/lib/Settings/register.d/40-kcc-werkplek.json` | `contactmoment`, `kccQuickAction`, `belplan`, `specialistBeschikbaarheid`, `doorverbinding`, `klantSentiment` |
| `procest/lib/Settings/kcc_werkplek_seed_data.json` | quick-action + belplan seed objects |
| `procest/lib/Settings/procest_register.json` | `complaint`, `complaintCategory`, `complaintDisposition`, `hearing` |

**Zero** of the schemas in `30-kcc.json` and `40-kcc-werkplek.json` carry an `x-schema-org` marker. `complaint` carries `"x-schema-org": "schema:Action"` — present, but wrong, and contradicted by its own description.

Pipelinq **already owns the same surface**, in schema.org, in English:

| Pipelinq schema | `x-schema-org` | Where |
|---|---|---|
| `contactmoment` | `schema:CommunicateAction` | `pipelinq_register.json` (+ `70-cti.json` patch) |
| `complaint` | `schema:Message` ⚠️ | `pipelinq_register.json` |
| `task` | `schema:Action` | `pipelinq_register.json` |
| `queue` | `schema:ItemList` | `pipelinq_register.json` |
| `skill` | `schema:DefinedTerm` | `pipelinq_register.json` |
| `agentProfile` | `schema:Person` | `pipelinq_register.json` |
| `ctiAdapterConfig`, `ctiEventLog`, `ctiAgentPresence` | — | `70-cti.json` |
| Berichtenbox family | `schema:Message` (partial) | `80-berichtenbox.json` |

…plus the controllers (`ContactmomentController`, `KccWerkplekController`, `CallbackController`, `RoutingController`, `CtiController`, `ZgwNotificationController`) and the capability specs (`contactmomenten`, `kcc-werkplek`, `klachtenregistratie`, `callback-management`, `queue-management`, `skill-routing`, `omnichannel-registratie`).

So the two apps declare **two contact-centre models**, and both declare a schema whose slug is `contactmoment`. This is a duplication, not a misfiling.

### Data reality

Every Dutch-named schema is **empty (0 rows)**: `Contactmoment`, `Belplan`, `Doorverbinding`, `Klant Sentiment`, `Specialist Beschikbaarheid`, `KCC Quick Action`, `Complaint`. Only `Routing Rule` (3), `KCC Agent` (3) and `Callback Request` (1) hold rows — 7 demo/seed rows. **There is no data migration.** Remodelling costs nothing.

### The schema.org marker convention

OpenRegister's resolver reads only the **schema-level** `"x-schema-org"` (sibling of `title`/`properties`). `JsonLdContextService::getImplementedTypes()` reads `implements[]`, `jsonld.type` and `x-schema-org`; `expandSchemaOrgMarker()` expands **only** `schema:`-prefixed CURIEs or absolute IRIs. A bare `"Person"` or a foreign prefix is silently dropped. `@type` and `x-schema-org-type` are decorative — and `@type` is separately consumed by `SchemaImport`'s `DialectDetector` for *inbound* JSON-LD, so a schema.org CURIE must **never** be left in `@type` inside a schema definition. Precedent: `procest/lib/Settings/register.d/25-brp-kvk.json` uses `"x-schema-org": "schema:Person"` / `"schema:Organization"`.

## Goals / Non-Goals

**Goals:**

- Pipelinq's register is the single canonical contact-centre model: schema.org-typed, English-propertied.
- Every contact-centre schema carries a correct schema-level `x-schema-org` marker (or deliberately carries none, when it is config).
- Extend what pipelinq already has; add only what is genuinely missing.
- Retire the `contactmoment` slug collision between the procest and pipelinq registers.
- Seed a realistic municipal contact-centre demo dataset.

**Goals (added by the ratified overrides):**

- Every contact-centre slug is English — `contactmoment` → `contactMoment` included.
- Every contact-centre enum value is English. Where the schema holds zero rows, that happens in **this** spec; where it holds rows (`task`, 4 of them), it happens in chain spec 2 with a migration.

**Non-Goals:**

- The Dutch/ZGW mapping layer itself → chain spec 4 (`kcc-dutch-mapping-layer`, `kind: code`).
- Deleting anything from procest → chain spec 5 (`kcc-procest-retirement`, `kind: code`).
- The `task` enum-value rewrite and its Repair step → chain spec 2. *Specified* here (requirement + old→new table + migration design); *implemented* there, because it touches rows and therefore needs code.
- The `contactmoment` → `contactMoment` rename and its ~12 reference sites → chain spec 3. Same reason: the register edit and its consumers must land atomically, which makes it `code`.
- Un-collapsing the pre-existing `task` slug collision across pipelinq/procest/planix (schema id 74). Surfaced by this change's measurements; out of scope. See Decision 6.
- Any UI work.

## Decisions

### Decision 1 — `kind: code`, split into a 5-spec chain (ADR-032)

**Re-derived twice.** First after the slug rename and enum anglicisation were ratified (both were originally deferred; both drag code behind them). Then again *during implementation*, which corrected the label on this spec itself.

Two facts decide the chain's shape:

- **A slug rename cannot land in a config spec.** The register edit alone would break `ContactmomentController`, the Vue stores, the `70-cti.json` patch and the manifest the moment it merged. Register + consumers must land atomically. Centre of mass: updating consumers → `code`.
- **An enum-value migration cannot land in a config spec.** Narrowing `task.type`/`status`/`priority` to English values while 4 live rows still hold `opvolgtaak`/`normaal`/`open` leaves those rows outside their own declared enum. Schema edit + row rewrite must land atomically, and the row rewrite is a Repair step → `code`.

Neither qualifies for ADR-032's thin-glue exception. A Nextcloud Repair step is a class with SPDX header, constructor, `getName()` and `run()` — comfortably past "≤20 LOC across ≤2 files" before it does any work, and the slug rename touches far more than two files. ADR-032 is explicit that the answer to "config + code in one envelope" is to split, not to bump the budget or hand-wave the exception. So we split, keeping ADR-032's canonical "declare → consume → delete" shape:

**This spec is `kind: code`, not `config` — corrected during implementation.** It was authored as `config` on the assumption that anglicising a *zero-row* enum is a pure JSON edit. It is not. An enum value is a literal, and the literals live in code: `ReportingService`'s SLA-target map and `calculateFcr()`, `ContactmomentQuickLog.vue` (a **write** path — it would have gone on writing now-invalid Dutch values), `CommunicationHistory.vue`, the two rapportage sections, and `complaintStatus.js`. Shipping the JSON without them would declare an enum that no code reads or writes — the schema and its literals are one atomic unit, exactly as argued above for the slug rename. The row count being zero makes the *data* migration free; it does not make the *code* migration disappear. Relabelled rather than split, because there is only one concern here (anglicise the contact-centre enums) and splitting it would put a schema and the literals keying on it in two separately-mergeable commits — the precise hazard ADR-032's atomicity rule exists to prevent.

| # | Change | `kind` | `depends_on` | Scope |
|---|---|---|---|---|
| 1 | `kcc-schemaorg-consolidation` (**this**) | `config` | — | Pipelinq register: add + extend + re-type + anglicise every **zero-row** enum + seed. Deletes nothing, migrates nothing. |
| 2 | `kcc-task-enum-migration` | `code` | 1 | Anglicise `task.type`/`status`/`priority`; Repair step rewrites the **4** Dutch-valued rows. |
| 3 | `kcc-contactmoment-slug-rename` | `code` | 1 | `contactmoment` → `contactMoment` across register, `70-cti.json`, controller, stores, manifest, 4 specs, tests, postman. **0 objects to migrate.** |
| 4 | `kcc-dutch-mapping-layer` | `code` | 2, 3 | Dutch/ZGW field names served from OR `Mapping` recipes at the controller seam. |
| 5 | `kcc-procest-retirement` | `code` | 4 | Delete procest's duplicate model and every referencing site. |

Spec 1 remains **genuinely** `config` — not `config` by omission. It only adds schemas and fields, and only anglicises enums on schemas that hold **zero rows** (`contactmoment`, `complaint`), which is a pure declarative edit with nothing to migrate. Every code-bearing consequence of the two ratified overrides is isolated in specs 2 and 3, where it can be reviewed against the 18 code gates rather than dragging a 200-property register patch through them.

Specs 2 and 3 are **independent of each other** (different schemas, different consumers) and can run in parallel once spec 1 merges.

The *requirements* for English slugs and English enum values are ratified **now**, in this change's `kcc-schemaorg-model` spec delta. Specs 2 and 3 implement them; they do not get to re-litigate them.

*Alternative considered:* one `mixed` spec with a thin-glue justification. Rejected — the code half is a Repair step plus a cross-cutting rename, not glue, and ADR-032's Stage-A post-mortem is precisely this failure mode.

*Alternative considered:* fold the enum rename into spec 1 and simply leave the 4 rows non-conformant. Rejected — that is silent data corruption, the exact failure mode the stop-and-escalate rule exists to prevent.

### Decision 2 — Merge into pipelinq's existing schemas, don't recreate the user's table 1:1

The user-ratified type table assumed nine new schemas. Four of them **already exist in pipelinq**, schema.org-typed and English-propertied. Creating `kccAgent` and `callbackRequest` as new schemas would re-commit the duplication this change exists to remove. The ratified *types* are honoured; the *containers* are pipelinq's existing ones where one already fits.

| Ratified: schema | Ratified: `x-schema-org` | Destination | Why |
|---|---|---|---|
| Contactmoment | `schema:CommunicateAction` | **merge → existing `contactmoment`** | Already exists, already `CommunicateAction`, already English. |
| Complaint | `schema:CommunicateAction` | **merge → existing `complaint`** (re-typed) | Already exists; currently mis-typed `schema:Message`. |
| Belplan | `schema:Schedule` | **new `callPlan`** | Nothing equivalent in pipelinq. |
| Doorverbinding | `schema:TransferAction` | **new `callTransfer`** | Nothing equivalent. |
| Klant Sentiment | `schema:Rating` | **new `contactSentiment`** | Nothing equivalent. |
| Specialist Beschikbaarheid | `schema:Schedule` | **merge → existing `agentProfile`** (`schema:Person`) | It is agent availability/skills/workload — the same rows as `agentProfile`. See Decision 3. |
| KCC Quick Action | `schema:Action` | **new `quickAction`** | Nothing equivalent. |
| KCC Agent | `schema:Person` | **merge → existing `agentProfile`** (`schema:Person`) | Same type, same concept. See Decision 3. |
| Callback Request | `schema:Reservation` | **merge → existing `task`** (`schema:Action`) | See Decision 4. |
| Routing Rule | *(none — config)* | **new `routingRule`**, no marker | Honoured exactly: config, no forced type. |

The two merges that deviate from the ratified table (`Specialist Beschikbaarheid`/`KCC Agent` → `agentProfile`; `Callback Request` → `task`) are flagged in Open Questions.

### Decision 3 — `kccAgent` + `specialistBeschikbaarheid` → one `agentProfile`

Procest declares agent availability twice (`kccAgent`: skills/status/workload/team; `specialistBeschikbaarheid`: expertises/status/queue-length/handling-time). Pipelinq declares it a third time as `agentProfile` (`schema:Person`: `userId`, `skills`, `maxConcurrent`, `isAvailable`) and a fourth as `ctiAgentPresence` (`70-cti.json`: `user_id`, `extension`, `presence_state`, `platform`).

`agentProfile` is the canonical row: one per Nextcloud user, `schema:Person`, already referenced by `skill-routing`. It absorbs both procest schemas. `ctiAgentPresence` stays as-is — it is telephony-platform presence keyed by extension, a different lifecycle (webhook-driven) and a different key; collapsing it is out of scope.

`schema:Schedule` is therefore **not** used for `specialistBeschikbaarheid`; it is used for `callPlan`, where the ratified table also puts it and where it actually fits (opening hours + routing steps).

### Decision 4 — `callbackRequest` → `task`

Pipelinq's `task` (`schema:Action`) already models a callback: `type: terugbelverzoek`, `callbackPhoneNumber`, `preferredTimeSlot`, `attempts`, `deadline`, `assigneeUserId`. Pipelinq's **`callback-management` spec (status: done)** states outright that callbacks live on `task`, mapped to VNG `InterneTaak` and `schema:Action`, and `CallbackController` is built on it. Introducing a separate `callbackRequest` schema typed `schema:Reservation` would create a second callback model in the app that is supposed to be consolidating them. The ratified `schema:Reservation` is not adopted; `task` keeps `schema:Action`.

### Decision 5 — `complaint` re-typed to `schema:CommunicateAction`

Three sources disagree today: the register says `schema:Message`, the schema description says "Mapped to Schema.org ComplainAction", and `openspec/specs/klachtenregistratie/spec.md` says `schema:ComplainAction`. **`schema:ComplainAction` does not exist in schema.org.** Per the ratified decision, a complaint is an inbound communication with a subject and a handler — the same family as `contactmoment`, differing in what it is `about`. All three sources converge on `schema:CommunicateAction`. This is the one behaviour-visible break in this change, hence the `klachtenregistratie` delta spec.

Procest's `complaint` cannot travel alone: it `$ref`s `complaintCategory`, and `complaintDisposition` `$ref`s it back with `onDelete: CASCADE`, and `hearing` hangs off it. All three satellites come with it. Pipelinq's `complaint.category` is today a five-value enum; it becomes a `$ref` to `complaintCategory` so per-tenant categories with SLA overrides survive.

### Decision 6 — Anglicise every slug, including `contactmoment` → `contactMoment` (**ratified override**)

Originally this design recommended keeping `contactmoment`, on the assumption that it was a data-bearing slug whose rename would be a costly migration. **That assumption was wrong, and measuring it against the live DB is what disproved it.** The rename was ratified, and it turns out to be nearly free.

Every schema this change creates is English-slugged (`callPlan`, `callTransfer`, `contactSentiment`, `quickAction`). `contactmoment` → `contactMoment` joins them, in chain spec 3.

**Why the rename is safe at the data layer** — the one fact that decides it:

- Objects bind to their schema by **numeric ID**, not by slug. On a live row: `_register = '16'`, `_schema = '74'`.
- Magic tables are named `oc_openregister_table_{registerId}_{schemaId}` — e.g. `oc_openregister_table_16_4476` for pipelinq's `contactmoment` (schema id 4476). **Both components are numeric IDs.** A slug is metadata on the schema row; it is not part of the table name and is not part of any object's foreign key.
- Therefore a slug rename **does not rename the magic table, does not re-materialise it, and does not orphan a single object.** It is a metadata update on one row of `oc_openregister_schemas`.
- And in any case: **pipelinq's `contactmoment` (schema 4476) holds 0 rows.** There is nothing to orphan.

This is worth stating plainly because the general fear is well-founded — the fleet has been bitten by schema-slug collisions and by register re-imports that drop schema linkage. Neither hazard applies here: the collision hazard is about *two apps claiming one slug* (which this change **fixes**, see below), and the linkage hazard is about `force=true` re-imports (which this change does not use).

**So the real cost of the rename is not data — it is references.** Every site that addresses the schema by its slug string must move in the same commit, or the app breaks. Inventoried in the Migration section.

**A caution the measurement surfaced.** The `task` slug is *already* claimed by pipelinq, procest and planix, and OpenRegister has collapsed them onto a **single schema row (id 74)** whose magic tables have drifted apart per register — `oc_openregister_table_19_74` (planix) has columns `project`, `zaak_uuid`, `sprint`-ish fields that pipelinq's `task` never declared. This is the schema-slug-collision failure mode, live, today. It is out of scope here, but it is the strongest possible argument for the "one slug, one owner" requirement this change ratifies — and it is why the enum migration in chain spec 2 must be scoped to pipelinq's register table (`16_74`) and must not touch rows belonging to procest (`17_74`) or planix (`19_74`).

### Decision 7 — Where the Dutch/ZGW mapping lives: **reuse OpenRegister's `MappingService`. Do not invent one.**

A mapping facility already exists in the fleet, and it is OpenRegister's:

- `openregister/lib/Db/Mapping.php` — a first-class entity (`mapping`, `unset`, `cast`, `passThrough`, `slug`, `version`) with `MappingMapper`.
- `openregister/lib/Service/MappingService.php` — `executeMapping(Mapping $mapping, array $input, bool $list = false): array`, a **sandboxed Twig** engine over dot-notation paths, with a compiled-template cache.
- `openregister/lib/Controller/MappingsController.php` — `api/mappings` CRUD plus `POST api/mappings/test` for dry-running a recipe against sample input.
- `openregister/lib/Twig/MappingExtension.php` / `MappingRuntime.php` — the Twig extension surface.

So the seam for chain spec 2 is:

- **Storage:** schema.org, English, in pipelinq's register. Unchanged by any Dutch request. ADR-001's "MUST NOT pollute the core data model" is satisfied structurally, not by convention.
- **Recipes:** OpenRegister `Mapping` rows, one per Dutch dialect surface (`kcc-contactmoment-to-klantinteracties`, `kcc-klantinteracties-to-contactmoment`, `kcc-complaint-to-klacht`, …). Versioned, slugged, testable via `POST api/mappings/test`.
- **Invocation:** the controllers pipelinq **already has** — `ContactmomentController` and `KccWerkplekController` for the Klantinteracties-shaped surface, `ZgwNotificationController` + `lib/Service/Zgw/*` for the ZGW surface. Each Dutch-facing method resolves its `Mapping` by slug and calls `MappingService::executeMapping()` on the way out (and the inverse recipe on the way in). No hand-rolled `array_combine` translation tables, no `NlFieldMapper` class.
- **Correlation:** pipelinq's existing `zgwResourceMapping` schema (`80-zgw-api-bridge.json`) already persists the pipelinq-entity ↔ ZGW-URL ↔ ETag link. Reused, not rebuilt.

*Not chosen:* the `components.mappings` block in a register file. `ConfigurationService::importFromJson()` routes `['mappings', 'jobs', 'synchronizations', 'rules', 'sources']` to **OpenConnector**, and only `if ($this->hasOpenConnector())`. Pipelinq must not hard-depend on OpenConnector for its Dutch API surface to work. Recipes are seeded via a pipelinq Repair step against `MappingMapper` instead. (`opencatalogi/lib/Settings/publication_register.json` carries the block, but empty.)

### Decision 8 — ADR-031: what is declarative, and what is not

Checked per surface. The declarative engine wins wherever it can:

| Behaviour | Verdict | Where |
|---|---|---|
| Complaint Awb lifecycle (`ontvangen` → … → `afgehandeld`, `* → ingetrokken`) | **Declarative.** Procest already expresses it as `x-openregister-lifecycle`; it moves across verbatim (with English states). No `ComplaintService` state machine in pipelinq. | `complaint` schema |
| Complaint / contactmoment / task notifications | **Declarative.** Pipelinq's `complaint`, `contactmoment` and `task` already carry `x-openregister-notifications`. Extended, not replaced. Canonical dialect only (ADR-031 / gate-18). | schemas |
| Awb deadline derivation (acknowledgement = +5 working days, resolution = +6 weeks) | **Declarative if the engine supports working-day arithmetic; otherwise imperative.** `x-openregister-calculations` is the right home. Spiking this is a task in this spec — it is exactly the "engine dependency surfaces early, in the config spec" property ADR-032 is built for. If the engine cannot express working days, it falls to chain spec 2 and is recorded as such. | `complaint` schema |
| Contact-moment volume / first-time-fix / average-handling-time roll-ups | **Declarative.** `x-openregister-aggregations` on `agentProfile` / `queue`. No analytics service class. | schemas |
| `contactmoment` read-logging + AVG attribution | **Declarative.** Procest's `x-openregister-processing` block (`logReads`, `attribution`, `subjectIdFields`) moves across, with `subjectIdFields` re-pointed at the English `contact` `$ref`. | `contactmoment` schema |
| Sentiment classification (NLP over a transcript) | **Imperative.** No declarative analogue — ADR-031's explicit "already-imperative work" carve-out. Stays a background job. Out of scope here. | chain spec 2/3 |
| Belplan routing evaluation (keuzemenu → skill match → queue overflow) | **Imperative.** A rules engine, not schema metadata. `routingRule` / `callPlan` store the *configuration* declaratively; evaluating it is code. | chain spec 2 |
| Dutch/ZGW field mapping | **Declarative data, imperative invocation** — OR `Mapping` recipes (Decision 7). Not a new service class. | chain spec 2 |

No new service class is introduced by this change. That is the point.

## Property mapping: Dutch → English

### `contactmoment` (procest `contactmoment` + `customerContact` → pipelinq `contactmoment`)

| Procest property | Pipelinq property | Status |
|---|---|---|
| `kanaal` / `direction` | `channel` / `direction` | exists (`direction` via `70-cti.json`) |
| `richting` | `direction` | exists |
| `startTijd` / `startedAt` | `contactedAt` | exists |
| `eindTijd` / `endedAt` | `endedAt` | **new** |
| `duurSeconden` / `durationSeconds` | `duration` | exists |
| `bellerIdentificatie` | `callerIdentifier` | **new** |
| `geidentificeerdeBurgerId` | `contact` (`$ref contact`) | exists — reuse, do not add an opaque id |
| `identificatieMethode` | `identificationMethod` | **new** (enum anglicised: `digid`, `bsn_verification`, `identity_questions`, `unidentified`) |
| `identificatieScore` | `identificationConfidence` | **new** |
| `kccMedewerkerId` / `kccAgentRef` | `agent` | exists |
| `gerelateerdeZaken` | `relatedCases` | **new** |
| `nieuweZaakIds` | `createdCases` | **new** |
| `aard` | `contactType` | **new** (enum: `information_request`, `status_request`, `complaint`, `report`, `new_application`, `transfer`) |
| `samenvatting` / `summary` | `summary` | exists |
| `volgensIntent` | `detectedIntent` | **new** |
| `firstTimeFix` | `firstTimeFix` | **new** (already English) |
| `transcriptie` | `transcript` | **new** |
| `transferNaar` | `transferredTo` | **new** |
| `customerRef` | `client` (`$ref client`) | exists |
| `customerName` / `customerPhone` / `customerEmail` | `contact` (`$ref contact`) | exists — reuse `schema:Person` |
| `outcome` | `outcome` | exists |
| `assignedTeam` / `assignedDomain` | `assignedTeam` / `assignedDomain` | **new** |
| `tags` | `tags` | **new** |
| `linkedContactMoment` | `relatedContactMoment` | **new** |

### `complaint` (procest `complaint` → pipelinq `complaint`)

| Procest property | Pipelinq property | Status |
|---|---|---|
| `klachtnummer` | `complaintNumber` | **new** (readOnly, `KL-{year}-{seq}`) |
| `klager.naam` / `.email` / `.telefoon` / `.bsn` | `contact` (`$ref contact`) | exists — reuse `schema:Person`; do not re-embed a person object |
| `onderwerp` | `title` | exists |
| `omschrijving` | `description` | exists |
| `ontvangstdatum` | `receivedAt` | **new** (Awb deadline basis) |
| `ontvangstkanaal` | `channel` | exists (enum extended: `counter`, `phone`, `email`, `letter`, `web`, `social`) |
| `categorie` | `category` | exists — **changes from enum to `$ref complaintCategory`** |
| `betrokkenMedewerker` | `subjectEmployee` | **new** |
| `betrokkenAfdeling` | `subjectDepartment` | **new** |
| `status` | `status` | exists (enum extended for the Awb lifecycle) |
| `behandelaar` | `assignedTo` | exists |
| `prioriteit` | `priority` | exists |
| `ontvangstbevestigingDeadline` | `acknowledgementDeadline` | **new** |
| `afhandelDeadline` | `slaDeadline` | exists |
| `verdagingMogelijk` | `extensionAvailable` | **new** |
| `verdagingJustificatie` | `extensionJustification` | **new** |
| `geescaleerdeZaak` | `escalatedCase` (`$ref case`) | **new** |
| `hoorgespreksWaiver.{datum,methode,bevestiging}` | `hearingWaiver.{date,method,confirmation}` | **new** |
| `x-schema-org: schema:Action` | `x-schema-org: schema:CommunicateAction` | **re-typed** |

Satellites: `complaintCategory` (`naam`→`name`, `slaOverride`, `defaultHandler`, `isActive` — already English, `schema:DefinedTerm`); `complaintDisposition` (`oordeel`→`judgment`, `toelichting`→`explanation`, `maatregelen`→`measures`, `afsluitdatum`→`closedAt`, `afsluitbrief`→`closingLetter`, `goedkeurder`→`approvedBy`, `goedkeuringStatus`→`approvalStatus`; `schema:Review`); `hearing` (`schema:Event`).

### `task` (procest `callbackRequest` → pipelinq `task`, `type: terugbelverzoek`)

| Procest property | Pipelinq property | Status |
|---|---|---|
| `contactMomentRef` | `contactMoment` (`$ref contactmoment`) | **new** |
| `customerPhone` | `callbackPhoneNumber` | exists |
| `preferredAgent` | `assigneeUserId` | exists |
| `reason` | `description` | exists |
| `scheduledFor` | `scheduledFor` | **new** (distinct from `deadline` — when to call, not when it expires) |
| `status` | `status` | exists |
| `attemptCount` | `attempts` | exists (array — count is its length) |
| `nextAttemptAt` | `nextAttemptAt` | **new** |

### `agentProfile` (procest `kccAgent` + `specialistBeschikbaarheid` → pipelinq `agentProfile`)

| Procest property | Pipelinq property | Status |
|---|---|---|
| `userRef` / `medewerkerId` | `userId` | exists |
| `skills` / `expertises` | `skills` (`$ref skill[]`) | exists |
| `availableForChannels` | `availableChannels` | **new** |
| `currentStatus` / `status` | `availabilityStatus` | **new** (enum: `available`, `busy`, `wrap_up`, `break`, `away`, `do_not_disturb`, `offline`) — supersedes the boolean `isAvailable` |
| `currentWorkload` / `gespreksInProgress` | `currentWorkload` | **new** |
| `huidigeWachtrijLengte` | `queueLength` | **new** |
| `gemiddeldeBehandelduur` | `averageHandlingTime` | **new** (seconds) |
| `lastContactCustomerRef` | `lastClient` (`$ref client`) | **new** |
| `team` | `team` | **new** |
| `laatsteUpdate` | `lastUpdatedAt` | **new** |

### `callPlan` (was `belplan`, `schema:Schedule`)

`naam`→`name`, `triggerNummer`→`triggerNumbers`, `routeringStappen`→`routingSteps`, `openingstijden`→`openingHours`, `terugvalActie`→`fallbackAction` (enum `voicemail`, `sms_callback`, `email_callback`), `prioriteit`→`priority`, `isActive`→`isActive`. Routing-step inner keys anglicise too: `keuzemenu`→`menu`, `vaardigheid_match`→`skill_match`, `wachtrij_overflow`→`queue_overflow`, `threshold_wachttijd_sec`→`thresholdWaitSeconds`, `fallback_rol`→`fallbackRole`.

### `callTransfer` (was `doorverbinding`, `schema:TransferAction`)

`contactmomentId`→`contactMoment` (`$ref`), `vanMedewerkerId`→`fromAgent`, `naarMedewerkerId`→`toAgent`, `naarWachtrij`→`toQueue` (`$ref queue`), `doorverbindingsReden`→`transferReason`, `contextOverdracht`→`handoverNotes`, `contextSnapshot`→`contextSnapshot`, `geaccepteerd`→`accepted`, `acceptatieTijd`→`acceptedAt`, `afgekeurdReden`→`rejectionReason`, `warmTransferStarted`→`startedAt`.

### `contactSentiment` (was `klantSentiment`, `schema:Rating`)

`contactmomentId`→`contactMoment` (`$ref`), `sentimentScore`→`ratingValue` (schema.org `Rating` vocabulary), `sentimentLabel`→`sentimentLabel` (enum `positive`, `neutral`, `negative`, `angry`), `triggerWoorden`→`triggerTerms`, `transcriptieSnippet`→`transcriptExcerpt`, `escalatieAanbevolen`→`escalationRecommended`, `escalatieLevel`→`escalationLevel` (enum `none`, `yellow`, `orange`, `red`), `createdAt`→`createdAt`.

### `quickAction` (was `kccQuickAction`, `schema:Action`)

`naam`→`name`, `actieType`→`actionType` (enum `provide_status`, `new_case`, `register_complaint`, `transfer`, `schedule_callback`, `send_email`, `send_document_copy`), `vereisteContext`→`requiredContext` (`has_open_case`, `is_identified`), `targetZaaktype`→`targetCaseType`, `template`→`template`, `permissies`→`permissions`, `volgorde`→`sortOrder`, `isActive`→`isActive`.

### `routingRule` (unchanged name — already English; **no** `x-schema-org`)

`name`, `priority`, `matchConditions[]{type,value}`, `assignedTeam`, `assignedQueue` (`$ref queue`, was `assignedDomain`), `escalationTeam`, `enabled`. This is configuration, not a schema.org thing. Per the ratified table, no type is forced.

## Seed Data

ADR-001 (hydra, data-layer) requires seeded demo data for any register change. Procest's 7 rows are replaced by a coherent municipal contact-centre dataset in `pipelinq/lib/Settings/demo_seed_data.json`. Realistic general-organisation data — a municipal contact centre — no personal data of real people, no live BSNs.

- **3 `routingRule`** — passport/ID → Civil Affairs; street lighting/pavement → Public Space Management; social-support (WMO) → Social Domain. Each with an escalation team of Front Office.
- **3 `agentProfile`** — a Civil Affairs generalist (`available`, workload 3, skills Dutch/English/Civil Affairs), a passport specialist (`available`, workload 5), a Public Space agent (`busy`, workload 12, queue length 4, average handling time 240s). Demo Nextcloud users, not real staff.
- **2 `callPlan`** — the general municipal number (menu → skill match → queue overflow at 180s → voicemail, Mo–Fr 08:00–17:00) and a permits direct line (skill match → overflow at 240s → SMS callback, Mo–Fr 09:00–12:00).
- **5 `quickAction`** — provide status, new case, register complaint, transfer, schedule callback; ordered, permissioned to `kcc_medewerker` (+ `klachtenfunctionaris` on register-complaint).
- **4 `complaintCategory`** — service, communication, timeliness, staff conduct; each with an SLA override in working days.
- **2 `task` (`type: terugbelverzoek`)** — one `scheduled` passport-renewal callback awaiting a BRP check, one `attempted` with `attempts: 1` and a `nextAttemptAt`.
- **2 `contactmoment`** — one inbound phone contact resolved first-time-fix, one inbound contact transferred to a specialist (with a matching `callTransfer` and a `neutral` `contactSentiment`).
- **1 `complaint`** — an inbound complaint in `acknowledged`, with `receivedAt`, a derived `acknowledgementDeadline` and `slaDeadline`, demonstrating the Awb lifecycle and both deadline fields.

Seeded via the register fragment's `components.objects` block (the pattern `30-kcc.json` and `40-kcc-werkplek.json` already use), keyed by stable `@self.slug` so re-import is idempotent.

## Sites still referencing the schemas being deleted

Inventoried by grep against both worktrees at HEAD. These are **chain spec 3** (`kcc-procest-retirement`) scope, recorded here so nothing is lost. Nothing in this change touches them.

**Procest — register/config (4):** `lib/Settings/register.d/30-kcc.json`, `lib/Settings/register.d/40-kcc-werkplek.json`, `lib/Settings/kcc_werkplek_seed_data.json`, `lib/Settings/procest_register.json` (complaint family + `customerContact` in the register's schema list).

**Procest — controllers (6):** `ComplaintController`, `ContactMomentController`, `BelplanController`, `KccRoutingController`, `SpecialistBeschikbaarheidController`, `SubstitutionController` — plus their 7 route entries in `appinfo/routes.php`.

**Procest — services (12):** `Service/ComplaintService`, `ComplaintAnalyticsService`, `DispositionService`, `HearingService`, `ContactMomentService`, `Kcc/ContactMomentService`, `BelplanRoutingService`, `Kcc/BelplanRoutingService`, `SentimentService`, `Kcc/SentimentService`, `DoorverbindingService`, `QuickActionService`, `KccWerkplekSeedDataService`, `BurgerIdentificationService`. (Note the duplicated pairs — `ContactMomentService` and `BelplanRoutingService` each exist twice, in `Service/` and `Service/Kcc/`.)

**Procest — jobs & repair (3):** `BackgroundJob/SentimentAnalysisJob`, `BackgroundJob/SpecialistBeschikbaarheidRefreshJob`, `Repair/SeedKccWerkplekData`.

**Procest — frontend (7):** `src/views/complaints/{ComplaintAnalyticsDashboard,ComplaintDashboardWidget,ComplaintDetail,ComplaintList}.vue`, `src/views/complaints/components/{ComplaintCreateDialog,DeadlinePanel}.vue`, `src/views/settings/KccIntegrationSettings.vue`, `src/views/settings/tabs/KlachtcategorieenTab.vue`, and the `AdminRoot.vue` tab registration.

**Procest — tests (12):** `tests/Unit/Controller/ComplaintControllerTest`, `tests/Unit/Service/{ComplaintService,ComplaintAnalyticsService,DispositionService,HearingService,BelplanRoutingService,KccWerkplekSeedDataService}Test`, `tests/Unit/Service/Kcc/{BelplanRoutingService,ContactMomentService}Test`, `tests/Unit/Settings/{KccFragmentTest,KccWerkplekFragmentTest}`.

**Procest — API collections (2):** `tests/integration/procest.postman_collection.json` (references `klachtnummer`), `tests/newman/kcc-werkplek-api.postman_collection.json` (5 hits).

**Procest — specs (4):** `openspec/specs/{complaint-management,kcc-klantcontact-integratie,kcc-werkplek-zaaksysteem-bridge}/spec.md` and the KCC entries in `openspec/features.overlay.json` — these must be archived or re-pointed at pipelinq, not silently orphaned.

**Procest — other (3):** `lib/Portal/PortalContributionProvider.php`, `lib/Settings/register.d/50-zaakportaal.json`, `lib/Settings/verwerkingsactiviteiten.json` (AVG processing-activity register entries for `contactmoment`).

**Procest — n8n workflows (3):** `n8n/complaint-{attachment-matcher,deadline-monitor,email-intake}.json` — these post Dutch complaint fields and will need re-pointing at the pipelinq mapping layer from chain spec 2.

**Pipelinq — the collision to retire:** procest and pipelinq both declare a schema with slug `contactmoment`. Deleting procest's removes the collision.

## Risks / Trade-offs

- **Re-typing `complaint` changes JSON-LD `@type` output.** → No pipelinq code branches on it (grepped). The `klachtenregistratie` delta spec records the break explicitly, and the schema `version` is bumped so the register-import version gate fires.
- **`complaint.category` changes from enum to `$ref complaintCategory`.** → A shape change on a data-bearing field. Pipelinq's `complaint` rows must be checked before merge; if any exist, the five enum values are seeded as `complaintCategory` objects with matching slugs so existing values resolve. Recorded as a task.
- **`agentProfile.isAvailable` (boolean) is superseded by `availabilityStatus` (enum).** → Keep `isAvailable` as a derived/deprecated field for one release rather than dropping it; `skill-routing` reads it today.
- **The Awb working-day deadline calculation may exceed `x-openregister-calculations`.** → Spiked in this spec, in isolation, exactly as ADR-032 intends. If the engine cannot express it, the chain does not stall: the field is declared and left null, and derivation moves to chain spec 2. Cost of being wrong is one property, not the change.
- **The merge targets hold live data — `agentProfile` 5 rows, `task` 10 rows, and `queue`/`skill` 153/3.** → **Hard constraint: the merges MUST be strictly additive.** Adding `availabilityStatus`, `currentWorkload`, `queueLength`, `averageHandlingTime`, `team` etc. to `agentProfile`, and `contactMoment`/`scheduledFor`/`nextAttemptAt` to `task`, must not drop, rename or re-type any property those existing rows already populate. In particular `agentProfile.isAvailable` is **retained** (deprecated, derived) rather than replaced by `availabilityStatus`, precisely because 5 rows and `skill-routing` depend on it. Any property removal on these four schemas is out of scope for the whole chain.
- **Procest keeps running on its Dutch model until chain spec 5.** → Intentional (expand-then-contract). The two models coexist for the length of the chain; nothing reads across.
- **The `task` slug is already collided across pipelinq/procest/planix on schema id 74, with divergent magic tables.** → Not caused by this change and not fixed by it. It does, however, mean chain spec 2's Repair step must be register-scoped (`_register = 16`) or it will rewrite another app's rows. Called out explicitly in the Migration section.
- **6 of the 10 live `task` rows are already non-conformant to their own declared enums** (`status: available|active|completed`). → Pre-existing drift. The migration converges all 10 rather than only the 4 Dutch ones, so the enum narrow at the end is actually safe.

## Migration

### Measured state of the live DB (not assumed — queried at HEAD)

Everything below is scoped to what is *actually there*. Rows live in per-register magic tables `oc_openregister_table_{registerId}_{schemaId}`, not in `oc_openregister_objects` (which is empty).

| Schema | id | Pipelinq rows (register 16) | Consequence |
|---|---|---|---|
| `contactmoment` | 4476 | **0** | Slug rename + enum anglicisation are **free**. Nothing to migrate. |
| `complaint` | 4475 | **0** | Re-type, status/priority/channel enum anglicisation, and `category` enum → `$ref` are all **free**. |
| `ctiAgentPresence` | 503 | **0** | Untouched anyway. |
| `task` | 74 | 10, of which **4** carry Dutch enum values | The **only** real value migration in the whole change. |
| `agentProfile` | 84 | **5** | Merge target — must be strictly additive. |
| `queue` | 83 | **153** | Untouched; additive constraint applies. |
| `skill` | 21 | **3** | Untouched; additive constraint applies. |
| all procest Dutch KCC schemas | — | **0** | Confirms the original finding: remodelling them costs nothing. |

The four Dutch-valued `task` rows, in full — every one of them in `oc_openregister_table_16_74`:

| `_uuid` | `type` | `priority` | `status` |
|---|---|---|---|
| `e7dc19a5-2edf-44e9-9bf9-c64523e0b534` | `opvolgtaak` | `normaal` | `open` |
| `e89ad623-0994-4806-9053-eb42853a4e26` | `opvolgtaak` | `normaal` | `afgerond` |
| `945f4872-21e0-47f6-90c3-8b09d32c18eb` | `opvolgtaak` | `normaal` | `in_behandeling` |
| `dd0b0b65-613e-423f-aa64-72f4e270694d` | `opvolgtaak` | `normaal` | `open` |

Two things fall out of this that a worst-case design would have missed:

- **No row uses `terugbelverzoek` or `informatievraag`.** The callback type this change consolidates onto is not yet in production use, so the `callbackRequest` → `task` merge lands on a clean field.
- **The other 6 `task` rows are *already* non-conformant** — they carry `priority: normal|high|urgent|low` and `status: available|active|completed`, none of which appear in the declared enums (`laag|normaal|hoog`, `open|in_behandeling|afgerond|verlopen`). Pre-existing drift, not caused by this change. Anglicising the enum actually *legalises* 6 of the 10 rows. The migration must be written to converge all 10, not just the 4.

### Migration A — enum values (chain spec 2, `kcc-task-enum-migration`)

**Facility used — found, not invented.** OpenRegister has no dedicated enum/value-migration facility (checked: `lib/Migration/` holds DB-schema migrations only). The fleet-standard shape for rewriting object values is an **app Repair step** driving OpenRegister's bulk writer, `OCA\OpenRegister\Service\Object\SaveObjects::saveObjects()` (chunked bulk object writes, via `ChunkProcessingHandler`). In-fleet precedent for exactly this shape: `procest/lib/Repair/SeedKccWerkplekData.php`. So chain spec 2 adds one Repair step; it does not add a service class and does not hand-roll SQL.

Old → new values:

| Schema.field | Old (Dutch) | New (English) |
|---|---|---|
| `task.type` | `terugbelverzoek` | `callback_request` |
| | `opvolgtaak` | `follow_up` |
| | `informatievraag` | `information_request` |
| `task.priority` | `laag` / `normaal` / `hoog` | `low` / `normal` / `high` |
| `task.status` | `open` | `open` (unchanged — already English) |
| | `in_behandeling` | `in_progress` |
| | `afgerond` | `completed` |
| | `verlopen` | `expired` |
| `contactmoment.channel` | `telefoon` / `balie` / `brief` | `phone` / `counter` / `letter` (0 rows — **spec 1**) |
| `contactmoment.outcome` | `afgehandeld` / `doorverbonden` / `terugbelverzoek` / `vervolgactie` / `opgelost` / `doorverwezen` | `handled` / `transferred` / `callback_requested` / `follow_up` / `resolved` / `referred` (0 rows — **spec 1**) |
| `complaint.priority` | `laag` / `normaal` / `hoog` | already English (`low`/`normal`/`high`/`urgent`) — no change |
| `complaint.status` | (Awb states) | English Awb states (0 rows — **spec 1**) |

Order of operations, and it matters:

1. **Widen, don't narrow.** The enum is first extended to accept **both** the Dutch and the English values. Merging this alone breaks nothing.
2. **Rewrite the rows.** The Repair step maps the 4 Dutch rows (and converges the 6 already-drifted rows) onto the English values, scoped by `_register = 16` and `_schema = 74`. **It must not touch `17_74` (procest) or `19_74` (planix)** — those rows belong to other apps sharing the collided `task` schema row.
3. **Narrow.** Only once the rows are clean does the enum drop the Dutch values.

This is expand-then-contract at the value level, and it means every intermediate state is valid. A single-shot narrow-and-rewrite would leave a window in which the rows are illegal against their own schema.

**Failure mode — stop and escalate, never corrupt.** If a row's value is not in the old→new table (e.g. more drift appears between now and apply), the Repair step MUST abort with the offending `_uuid` and value, leave the enum widened, and change nothing. It MUST NOT guess, MUST NOT null the field, and MUST NOT drop the row. Same rule as the `complaint.category` migration (Decision/Risk above). A 4-row migration is small enough that a human resolving an outlier by hand is entirely practical — that is the point of doing it at this size rather than later.

### Migration B — the `contactmoment` → `contactMoment` slug rename (chain spec 3)

**There is no object migration.** Per Decision 6: objects bind to schema by numeric ID, magic tables are ID-named, and the schema holds 0 rows regardless. The rename is a metadata update on one `oc_openregister_schemas` row, plus a synchronised update of every site that addresses the schema by its slug string.

Reference sites that must move in the same commit:

- `lib/Settings/pipelinq_register.json` — the schema's `slug` and its entry in `components.registers.pipelinq.schemas`
- `lib/Settings/register.d/70-cti.json` — the CTI patch keys on `"slug": "contactmoment"`
- `lib/Settings/register.d/71-kcc-contactcentre.json` — the `$ref`s from `callTransfer` and `contactSentiment` (created by spec 1 pointing at the old slug; spec 3 re-points them)
- `lib/Controller/ContactmomentController.php` and the `contactmoment_schema` settings config key
- The Vue object store registration and any `saveObject('contactmoment', …)` call sites
- `src/manifest.json` — page/widget config addressing the schema
- Specs: `contactmomenten`, `contactmomenten-rapportage`, `omnichannel-registratie`, `klantbeeld-360`
- Tests + the pipelinq postman collections

**Failure mode.** If the register re-import creates a *second* schema row (new slug) rather than renaming the existing one, that is the orphaning hazard the fleet has been bitten by. Spec 3 MUST verify, immediately after import, that `oc_openregister_schemas` holds exactly one row for the concept and that its id is still **4476** — i.e. that the rename mutated the existing row rather than inserting a new one. If a second row appears, roll back the import and do the rename via an explicit slug update on the existing row instead. Do **not** use `force=true` on the re-import; that is documented to drop schema linkage.

### Deployment order

1. **Spec 1 (this).** Register gains schemas + fields; zero-row enums anglicised; nothing removed; nothing migrated. Procest untouched. Safe alone.
2. **Specs 2 and 3, in parallel** (independent of each other, both depend only on 1). Enum values converge on English; the slug becomes `contactMoment`.
3. **Spec 4** — OR `Mapping` recipes + controller seam. Dutch/ZGW consumers now get their field names from a mapping layer, never from stored fields.
4. **Spec 5** — procest's duplicate model and its ~40 referencing sites are deleted.

**Rollback.** Spec 1 is purely additive — reverting the register fragment and the four schema patches restores the prior state; no data is destroyed because nothing is deleted or rewritten. Spec 2's widen-rewrite-narrow sequence is revertible at each step (re-widen the enum, rewrite the 4 rows back). Spec 3 is revertible by renaming the slug back and reverting the reference commit, because no object ever moved.

## Resolved decisions (previously open; now ratified)

1. **`kccAgent` + `specialistBeschikbaarheid` → `agentProfile`, and `callbackRequest` → `task`.** **RATIFIED: merge.** Constrained to be strictly additive — those schemas hold 5 and 10 live rows. Decisions 3 and 4.
2. **The slug `contactmoment` → `contactMoment`.** **RATIFIED: rename** (overriding this design's original recommendation to keep it). Measurement showed the rename is metadata-only and the schema holds 0 rows, so the original objection — cost of a data migration — was unfounded. Lands in chain spec 3. Decision 6 + Migration B.
3. **Dutch enum values are anglicised.** **RATIFIED: do it now** (overriding this design's original recommendation to defer). Zero-row enums (`contactmoment`, `complaint`) are anglicised in **this** spec at no cost. The `task` enums, which back 4 Dutch-valued rows, are anglicised in chain spec 2 with a widen → rewrite → narrow migration. Migration A.
4. **`ctiAgentPresence` stays a separate schema.** **RATIFIED: leave**; revisit after the CTI adapter stabilises. Decision 3.
5. **`complaint.category` becomes a `$ref complaintCategory`,** seeding the five prior enum values as objects with matching slugs; stop-and-escalate if a row fails to resolve. **RATIFIED.** The schema holds **0 rows**, so this is free in practice — the seeding is belt-and-braces for other environments.

## Open Questions

1. **Does `x-openregister-calculations` support working-day arithmetic** (Awb: +5 working days, +6 weeks)? Determines whether the two complaint deadlines are declarative (ADR-031) or imperative. **RATIFIED approach: spike declarative first; fall back to the mapping-layer spec (chain spec 4) if the engine cannot express it.** Spiked as task 1.1, in isolation, so a negative result costs one property rather than stalling the chain.

   **ANSWERED (task 1.1 spike, measured against OpenRegister at HEAD) — NO, not faithfully. Both deadlines are declared as plain date properties; derivation is handed to the chain.**

   What the engine actually offers, read off `openregister/lib/Service/Calculation/CalculationAnnotationValidator.php::VALID_OPS` and `CalculationEvaluator`:

   - The operator vocabulary is `prop, lit, concat, if, not, and, or, +, -, *, /, %, eq, ne, lt, lte, gt, gte, now, diffDays, formatDate, dateDiff, dateAdd, sequence, max, min, coalesce, abs, round, year, monthsElapsed, sha256`.
   - `dateAdd` is the only date-shifting operator: `{ "dateAdd": { "date": <expr>, "amount": <expr>, "unit": "days|weeks|months|years" } }`, or an ISO-8601 `duration`. `intervalFromAmountUnit()` folds weeks to `P{n*7}D` — plain `DateInterval` arithmetic.
   - **There is no working-day / business-day unit, and no holiday calendar anywhere in the calculation engine** (`grep -i 'working_day|business_day|holiday' lib/Service/Calculation/` → zero hits). Nor is there a day-of-week operator to hand-roll one with: `formatDate` could emit a weekday, but the Algemene termijnenwet also extends a term that lands on a general holiday, and no operator can see a holiday.

   So the split verdict is:

   - `slaDeadline` = `receivedAt` + 6 weeks **is** expressible (`dateAdd`, `unit: weeks`) — but only in its default form. The moment a `complaintCategory.slaOverride` applies, the term is expressed **in working days**, which the engine cannot compute. A calculation that is correct only until a category overrides it, and silently wrong afterwards, is worse than no calculation.
   - `acknowledgementDeadline` = `receivedAt` + 5 **working** days is not expressible at all.

   Per the ratified fallback, **both are declared as plain `date` / `date-time` properties** on `complaint`, with the reason recorded in each property's description, and the derivation moves to the chained `kind: code` change. The cost of the negative result is exactly what this design predicted: two properties, not the chain.
2. **The `task` slug collision (schema id 74, shared by pipelinq / procest / planix with divergent magic tables) is real and live.** This change neither causes nor fixes it, and works around it by register-scoping the Repair step. It is a latent data-integrity hazard for three apps and probably deserves its own change. **Provisional: file a separate issue; do not widen this chain.**
