# lead-scoring-win-probability Specification (delta)

## MODIFIED Requirements

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
