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
