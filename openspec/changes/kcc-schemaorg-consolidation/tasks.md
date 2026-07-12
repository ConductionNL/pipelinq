# Tasks: kcc-schemaorg-consolidation

`kind: config` — spec 1 of **5** (ADR-032). This change touches **only** declarative register JSON in Pipelinq. It adds, extends and re-types; it deletes nothing and migrates no object values. No PHP is written. Procest is untouched.

The two ratified anglicisations are split by measured cost:

- **Enum values on zero-row schemas** (`contactmoment`, `complaint`) — anglicised **here**, free, nothing to migrate.
- **Enum values on `task`** (4 live Dutch-valued rows) → chain spec 2 (`kcc-task-enum-migration`, `code` — needs a Repair step).
- **The `contactmoment` → `contactMoment` slug rename** (0 rows, but ~12 reference sites that must move atomically) → chain spec 3 (`kcc-contactmoment-slug-rename`, `code`).

## 1. Engine spike (do first — it gates task 3.2)

- [x] 1.1 Determine whether `x-openregister-calculations` can express Awb working-day arithmetic (`acknowledgementDeadline` = `receivedAt` + 5 working days; `slaDeadline` = `receivedAt` + 6 weeks). Record the answer in design.md. If it cannot, declare both as plain dates and hand derivation to chain spec 4 — do not stall the chain.
      **Answer: NO.** `dateAdd` (the only date-shifting operator) takes `days|weeks|months|years` or an ISO duration — plain `DateInterval` arithmetic. There is no working-day unit, no holiday calendar and no day-of-week operator anywhere in the calculation engine. The 6-week term alone *is* expressible, but it is superseded by `complaintCategory.slaOverride`, which is itself stated in **working days** — so a calculation would be right until a category overrode it and silently wrong afterwards. Both deadlines are therefore declared as plain `date` / `date-time` properties, each carrying the reason in its description; derivation moves to the chain. Full evidence in design.md, Open Questions #1.

## 2. New contact-centre schemas (English slugs, English enums)

- [x] 2.1 Create `lib/Settings/register.d/71-kcc-contactcentre.json` and list `callPlan`, `callTransfer`, `contactSentiment`, `quickAction`, `routingRule`, `complaintCategory`, `complaintDisposition`, `hearing` under `components.registers.pipelinq.schemas`.
- [x] 2.2 Declare `callPlan` (`schema:Schedule`) and `routingRule` (**no** `x-schema-org` — it is config), using the English property names and enum values in design.md.
- [x] 2.3 Declare `callTransfer` (`schema:TransferAction`) and `contactSentiment` (`schema:Rating`; `sentimentScore` → `ratingValue` per the Rating vocabulary; enums `positive|neutral|negative|angry` and `none|yellow|orange|red`).
- [x] 2.4 Declare `quickAction` (`schema:Action`) with English `actionType` / `requiredContext` enums.
- [x] 2.5 Declare `complaintCategory` (`schema:DefinedTerm`), `complaintDisposition` (`schema:Review`, `$ref complaint` with `onDelete: CASCADE`) and `hearing` (`schema:Event`).

## 3. Extend Pipelinq's existing schemas — STRICTLY ADDITIVE

`agentProfile` (5 rows), `task` (10), `queue` (153), `skill` (3) hold live data. Do not remove, rename or re-type any property those rows populate.

- [x] 3.1 Extend `contactmoment` in `lib/Settings/pipelinq_register.json` with the fields from design.md's mapping table (`callerIdentifier`, `identificationMethod`, `identificationConfidence`, `contactType`, `detectedIntent`, `firstTimeFix`, `transcript`, `transferredTo`, `relatedCases`, `createdCases`, `endedAt`, `assignedTeam`, `assignedDomain`, `tags`, `relatedContactMoment`). Reuse the existing `client`/`contact`/`agent` refs — no opaque id fields. Port procest's `x-openregister-processing` block, re-pointing `subjectIdFields` at `contact`. Bump `version`. **Leave the slug as `contactmoment` — the rename is chain spec 3.**
- [x] 3.2 Extend `complaint`: re-type `x-schema-org` from `schema:Message` to `schema:CommunicateAction`, fix the description (it claims the non-existent `schema:ComplainAction`), add the Awb fields (`complaintNumber`, `receivedAt`, `acknowledgementDeadline`, `extensionAvailable`, `extensionJustification`, `subjectEmployee`, `subjectDepartment`, `escalatedCase`, `hearingWaiver`), port `x-openregister-lifecycle`, and change `category` from enum to `$ref complaintCategory`. Bump `version`.
- [x] 3.3 Extend `task` for callbacks (`contactMoment` `$ref`, `scheduledFor`, `nextAttemptAt`) — `type`, `callbackPhoneNumber`, `preferredTimeSlot` and `attempts` already exist. Keep `schema:Action`; do **not** create a `callbackRequest` schema. **Leave `type`/`status`/`priority` enum values Dutch — anglicising them requires the row migration in chain spec 2.** Bump `version`.
- [x] 3.4 Extend `agentProfile` (absorbing `kccAgent` and `specialistBeschikbaarheid`) with `availableChannels`, `availabilityStatus` (enum), `currentWorkload`, `queueLength`, `averageHandlingTime`, `lastClient`, `team`, `lastUpdatedAt`. **Retain the boolean `isAvailable`, marked deprecated** — 5 live rows populate it and `skill-routing` reads it. Bump `version`.
- [x] 3.5 Declare the aggregations that replace analytics service code (ADR-031): contact-moment volume, first-time-fix rate and average handling time as `x-openregister-aggregations` on `agentProfile` / `queue`.

## 4. Anglicise the zero-row enums (free — no migration)

Before each task below, re-verify the schema still holds 0 rows (`oc_openregister_table_{registerId}_{schemaId}`). If rows have appeared, STOP and escalate — never narrow an enum under live data.

- [x] 4.1 Anglicise `contactmoment.channel` (`telefoon`/`balie`/`brief` → `phone`/`counter`/`letter`; keep `email`, `chat`, `social`, `sms`) and `contactmoment.outcome` (`afgehandeld`/`doorverbonden`/`terugbelverzoek`/`vervolgactie`/`opgelost`/`doorverwezen` → `handled`/`transferred`/`callback_requested`/`follow_up`/`resolved`/`referred`). Confirm 0 rows first.
- [x] 4.2 Anglicise `complaint.status`, `complaint.priority` and `complaint.channel` to English values (English Awb lifecycle states per the `klachtenregistratie` delta). Confirm 0 rows first.
      Row count re-verified live before the edit (read-only): `oc_openregister_table_16_4475` (complaint) and `oc_openregister_table_16_4476` (contactmoment) **do not exist** in register 16 — the schemas hold 0 rows, so both anglicisations are free. `complaint.priority` and `complaint.channel` were already English; `channel` gains `social`, `status` gains the four Awb states (`acknowledged`, `hearing_scheduled`, `hearing_completed`, `withdrawn`) — additive, nothing narrowed.

## 5. Data safety

- [x] 5.1 Seed the five prior `complaint.category` enum values (service, product, communication, billing, other) as `complaintCategory` objects with matching slugs, so any existing complaint row resolves once `category` becomes a `$ref`. Pipelinq's `complaint` holds 0 rows today; if rows exist and any fails to resolve, stop and escalate rather than corrupt it.

## 6. Seed data (ADR-001)

- [x] 6.1 Seed the municipal contact-centre dataset from design.md's Seed Data section via `components.objects` in the register fragment, keyed by stable `@self.slug` for idempotent re-import: 3 `routingRule`, 3 `agentProfile`, 2 `callPlan`, 5 `quickAction`, 4 `complaintCategory`, 2 callback `task`, 2 `contactmoment`, 1 `callTransfer`, 1 `contactSentiment`, 1 `complaint`. Realistic general-organisation data; no real personal data, no live BSNs.
      Two deliberate deviations, both to stay additive: (a) **`agentProfile` is not re-seeded** — the register already ships exactly 3 agent-profile seed objects (`agent-jan-de-vries`, `agent-fatima-el-amrani`, `agent-pieter-bakker`), which are *enriched* with the merged availability/workload/team fields instead. Adding three more would duplicate the agents this change exists to consolidate, and rewriting their existing `isAvailable` values would mutate the 5 live rows on re-import. (b) **7 `complaintCategory` objects, not 4** — the 4 municipal demo categories, plus the 5 legacy enum values from task 5.1 (`service` and `communication` serve both roles).

## 7. Tests

- [x] 7.1 Add a register-fragment PHPUnit test covering the vocabulary contract: every ratified `x-schema-org` marker is present and `schema:`-prefixed, `routingRule` has none, no marker is bare or foreign-prefixed, no schema definition leaves a schema.org CURIE in `@type` (it would trip `SchemaImport`'s `DialectDetector`), no schema declares a banned Dutch property name, and all enums on the new schemas plus `contactmoment` and `complaint` are English-valued.
- [x] 7.2 Add a PHPUnit test asserting the additive constraint: every property `agentProfile`, `task`, `queue` and `skill` declare today is still declared with an unchanged `type`, and `agentProfile.isAvailable` survives.
- [x] 7.3 Add a PHPUnit test asserting the seed objects materialise for every schema and that a second import creates no duplicates.

All three live in `tests/Unit/Settings/KccContactCentreRegisterTest.php` (17 tests, 2 868 assertions). The test reads the register the way OpenRegister does — the monolith deep-merged with every `register.d/*.json` fragment, with `components.objects` and the register's `schemas[]` membership *unioned*, mirroring `ConfigFileLoaderService` (ADR-037) — so the assertions hold against the shape that is actually imported, not against one file in isolation.

## 8. Verify

- [ ] 8.1 Import the register and confirm a `complaint` object resolves to JSON-LD `@type: CommunicateAction`, the eight new schemas materialise with their seed objects, and the 5 `agentProfile` / 10 `task` / 153 `queue` rows are all still readable and unmodified.
      **NOT DONE — deliberately.** The live dev instance is shared with other sessions and serves Pipelinq from the main checkout, which is on another branch with ~98 dirty files. Importing this register would require deploying this worktree over that shared instance. Left for the reviewer / apply step on a clean instance. What *was* verified live, read-only: schema ids (`contactmoment` 4476, `complaint` 4475, `task` 74, `agentProfile` 84, `queue` 83, `skill` 21) and row counts (`task` 10, `agentProfile` 5, `queue` 153, `skill` 3; `contactmoment` and `complaint` have no magic table in register 16 at all → 0 rows). The contract this task asserts — the marker, the eight schemas, their seed objects, and the untouched properties of the four data-bearing schemas — is asserted offline by the tests in 7.1–7.3.
- [x] 8.2 Run `composer check:strict` and PHPUnit in Pipelinq; fix any pre-existing findings surfaced in the files touched.
      PHPUnit **1561 tests / 7 661 assertions, 0 failures** (15 skipped). PHPCS **0 errors** — two pre-existing errors (`SlaEngineService.php:59`, `WorklistService.php:63`, both over-long lines) were fixed here. Psalm 0 errors. PHPStan 31 errors, **identical to the count on `origin/development`** — none introduced by this change. PHPMD 7 violations, all in files this change does not touch. ESLint on the touched frontend files: 0 errors.

---

## Acceptance criteria

- Every contact-centre schema carries its ratified `schema:`-prefixed `x-schema-org` marker; `routingRule` deliberately carries none.
- No contact-centre schema stores a Dutch property name.
- Every enum on a zero-row schema is English. `task`'s enums stay Dutch **only** until chain spec 2 migrates its 4 rows — a scoped hand-off, not an omission.
- `complaint` resolves to `schema:CommunicateAction` — not `schema:Message`, not the non-existent `schema:ComplainAction`.
- The four data-bearing schemas are extended additively: no property removed, renamed or re-typed. `agentProfile.isAvailable` survives.
- No PHP is written. Lifecycle, notifications, aggregations and read-logging are declared as `x-openregister-*` metadata (ADR-031).
- Nothing is deleted from Procest; it remains fully functional (expand-then-contract).

## Quality reminders

- i18n keys are ENGLISH (ADR-007 / ADR-025).
- Notification blocks use the canonical `x-openregister-notifications` dialect only — the legacy dialect hard-fails gate-18.
- Bump `version` on every patched schema, or OpenRegister's register-import version gate will not fire and the change silently will not land. Never re-import with `force=true` — it drops schema linkage.
- Never narrow an enum while live rows hold values outside it. Verify the row count before every enum edit; stop and escalate rather than corrupt.
- Re-validate the register JSON after every edit — one invalid page discards the whole backend delta.
- Do not use sed/awk/scripts to edit the register JSON; edit it directly.
- Out of scope here, all inventoried in design.md: the `task` enum migration (chain 2), the `contactMoment` slug rename (chain 3), the Dutch/ZGW mapping layer (chain 4), and the Procest retirement — 4 register files, 6 controllers, 12+ services, 2 background jobs, 1 Repair step, 7 Vue views, 12 test files, 2 postman collections, 4 specs, 3 n8n workflows (chain 5).
