# Tasks: lead-scoring-win-probability

## 1. Declarative calculation

- [x] 1.1 Add the `winProbability` calculation to the `lead` schema's `x-openregister-calculations` in `lib/Settings/pipelinq_register.json` (integer, `materialise: false`, nested `if`/`gt`/`*`/`/`/`dateDiff` decay per design.md)
  - files: `lib/Settings/pipelinq_register.json`
  - Acceptance criteria:
    - Uses only operators already present in the register (`if`, `gt`, `*`, `/`, `dateDiff` with `unit: days`)
    - No new schema property is required (derived, `materialise: false`, appears in read output)
    - Register JSON stays valid after the edit

## 2. Surface declaratively

- [x] 2.1 Add `winProbability` to the `LeadDetail` `lead-deal` data widget `content.include` in `src/manifest.json`
- [x] 2.2 Add a colour-banded `winProbability` column to the `Leads` index in `src/manifest.json`, reusing the existing `lead-probability` cell widget
  - files: `src/manifest.json`
  - Acceptance criteria:
    - No new Vue component is added; the index column reuses `widget: "lead-probability"`
    - `src/manifest.json` remains valid and passes manifest validation

## 3. Seed data

- [x] 3.1 Seed leads spanning hot/warm/cold bands (municipality, consultancy, travel agency) in the register so the surfaced value is verifiable on a fresh install
  - files: `lib/Settings/pipelinq_register.json`
  - Acceptance criteria:
    - At least one open lead per archetype, with `probability` and `stage` set
    - Example ids in any doc use the nil UUID; seed objects follow the register's existing slug style

## 4. Verify

- [x] 4.1 Import the register into OpenRegister and confirm each open lead's read payload includes `winProbability`, matching the band arithmetic for its age
  - Acceptance criteria:
    - A freshly-imported lead with `probability: 60` reads `winProbability: 60`
    - The value recomputes on read (no write needed) as the object ages past the 14/30/60-day thresholds
    - LeadDetail Deal widget and the Leads index column both render the field

## Acceptance criteria (change-level)

- `winProbability` is derived declaratively on the `lead` schema; no service class, no new schema, no PHP.
- The field is visible on the pipeline list (colour-banded) and the deal detail page without new frontend code.
- Seed leads make the value observable on a fresh install.
