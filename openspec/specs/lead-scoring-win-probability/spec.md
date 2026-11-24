---
status: done
---

# Spec: Lead Scoring — Win Probability

**OpenSpec changes**: [lead-scoring-win-probability](../../changes/archive/2026-07-12-lead-scoring-win-probability/) _(archived 2026-07-12)_

## Purpose

Defines a recency-decayed win probability derived on the `lead` schema and surfaced on
the pipeline list and deal detail. `winProbability` is a declarative
`x-openregister-calculations` field (`materialise: false`) equal to the lead's
stage-denormalised `probability` decayed by inactivity — full within 14 days of the
last touch, then stepped down at 30/60 days — so a stalling deal visibly cools on read
with no write. It complements, and does not replace, the existing `qualificationScore`
and `weightedValue` calculations. Surfacing is purely declarative (manifest data
widget + a colour-banded index column reusing the existing `lead-probability` cell
widget) — no service class, no new schema, no PHP (ADR-031/ADR-032, `kind: config`).

**Standards**: Schema.org (`Demand`); industry CRM win-probability consensus (Odoo,
Dolibarr, Salesforce Einstein, Zoho, HubSpot)
**Primary feature tier**: V1

## Requirements

### Requirement: Recency-decayed win probability is derived on every lead

@e2e exclude the four decay bands are keyed on `@self.updated` — OpenRegister's own write timestamp, which no client may set — so "a lead untouched for 45 days" and "for 90 days" are states a browser session cannot construct, and a Playwright run cannot wait 90 days to reach them. The decay itself is not pipelinq code at all: it is a declarative `x-openregister-calculations` expression with `materialise: false` on the `lead` schema in lib/Settings/pipelinq_register.json (`components.schemas.lead.configuration.x-openregister-calculations.winProbability`), evaluated by OpenRegister's expression engine on every read, and this spec's own Purpose records that no service class exists to unit-test. What pipelinq is responsible for — never writing the field itself and passing OpenRegister's computed value through untouched — is asserted by tests/Unit/Service/LeadServiceTest.php (testCreateLeadWritesLeadAndReturnsMaterialisedCalculations, which asserts `winProbability` is absent from the saved object and present unmodified on the returned lead), and the declaration's shape — a valid `type`, a v1-vocabulary expression, and every `prop` reference resolving to a declared property or a known `@self.*` field — by tests/Unit/Settings/RegisterAnnotationsTest.php (testCalculationAnnotationsAreWellFormed). NOTE, measured in run 31473685688: the value does NOT currently reach any pipelinq surface — see the surfacing requirement below, tracked as pipelinq#774 — so there is no end-to-end assertion to point at here either.

The `lead` schema SHALL declare a `winProbability` calculation
(`x-openregister-calculations`) that yields an integer 0–100. The value SHALL be
the lead's `probability` decayed by inactivity, measured as the whole-day
difference between `@self.updated` and now:

- within 14 days of the last activity: `probability` (no decay)
- older than 14 and up to 30 days: 80% of `probability`
- older than 30 and up to 60 days: 50% of `probability`
- older than 60 days: 25% of `probability`

The calculation SHALL be `materialise: false` so it is recomputed on every read and
requires no write to stay current. It SHALL depend only on single-object inputs
(`@self.updated` and the lead's own `probability`) so it is expressible declaratively
per ADR-031 — the stage's base probability is already denormalised onto the
`probability` field, so no cross-schema join is needed. No service class SHALL be
introduced.

#### Scenario: Fresh lead keeps full probability
- **WHEN** a lead with `probability: 60` was last updated 3 days ago
- **THEN** its `winProbability` reads 60

#### Scenario: Stalled lead cools
- **WHEN** a lead with `probability: 60` has not been touched for 45 days
- **THEN** its `winProbability` reads 30 (50% band)

#### Scenario: Long-cold lead reads a quarter
- **WHEN** a lead with `probability: 80` has not been touched for 90 days
- **THEN** its `winProbability` reads 20 (25% band)

#### Scenario: Zero probability stays zero
- **WHEN** a lead has `probability: 0`
- **THEN** `winProbability` reads 0 regardless of age

### Requirement: Win probability is surfaced on the pipeline list and deal detail

The win probability SHALL be visible to a salesperson on the `Leads` index, on the lead page's Deal data and on the pipeline board's lead cards, as boards PqLeads, PqLead and PqPipeline draw it. The index column SHALL be labelled "Win chance" and SHALL show `winProbability` with the shared colour-banded `lead-probability` cell widget; the lead score (`qualificationScore`) SHALL keep a label of its own and SHALL NOT be shown as "Win chance". Because `winProbability` is a calculation with `materialise: false`, each of these pages SHALL request it from OpenRegister with `_extend[]=calculations` through a declared `extend` option on the page, not through page-specific code.

#### Scenario: Deal page shows win probability
- **WHEN** a user opens a lead's detail page
- **THEN** the Deal widget renders `winProbability` alongside `value`, `probability`, and `status`

#### Scenario: Pipeline list shows a colour-banded win probability column
- **WHEN** a user views the `Leads` index
- **THEN** a "Win chance" column renders each lead's decayed probability with the shared colour-banded cell widget, so cold deals read visually distinct from hot ones

#### Scenario: A cold lead on the pipeline board
- **GIVEN** a lead with `probability: 60` untouched for 45 days
- **WHEN** a user opens the pipeline board
- **THEN** its card shows a win chance of 30%

#### Scenario: The lead score is not called win chance
- **WHEN** a user views the `Leads` index
- **THEN** no column labelled "Win chance" shows `qualificationScore`

### Requirement: Seed data spans the win-probability bands

The register seed SHALL include representative leads whose `probability` and notes
place them in the hot, warm and cold bands as they age. The decay is keyed on
`@self.updated`, which OpenRegister writes and no client or import may set, so on a
fresh install every seed reads its full probability; the warm and cold seeds reach
their decayed bands only once they have stood still past 14, 30 or 60 days. The
bands themselves are proven against the lead schema's real `winProbability`
expression in the unit suite, not on a fresh install.

#### Scenario: Seed leads read their full probability on a fresh install
@e2e exclude a fresh import stamps every seed's `@self.updated` with the import moment, so the bands cannot be observed until the seeds age; the band boundaries are asserted against the real expression by the unit suite.
- **WHEN** the register seed is imported and the `Leads` index is opened
- **THEN** each seeded open lead's Win chance reads its own probability
