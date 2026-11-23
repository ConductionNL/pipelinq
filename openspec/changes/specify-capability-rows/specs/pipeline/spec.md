# pipeline Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `pipeline-kanban`.

## ADDED Requirements

### Requirement: A lead dragged to another stage column moves to that stage

The pipeline board SHALL let a user drag a lead card from one stage column to another and SHALL save the new stage on the lead.

#### Scenario: Moving a lead from Contacted to Qualified

- GIVEN a lead in the Contacted stage on the pipeline board
- WHEN a user drags its card to the Qualified column
- THEN the lead's stage is saved as Qualified
- AND the card is still in the Qualified column after a reload
