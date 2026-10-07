# pipeline Specification (delta)

## ADDED Requirements

### Requirement: The board names leads that are on no board (REQ-RAF-020)

The pipeline board SHALL list, in a notice above the columns, every lead whose
pipeline does not exist or whose stage is not a stage of its pipeline, with a
link to the lead and the reason.

#### Scenario: Leads on a deleted pipeline

- GIVEN six leads on a pipeline that was deleted
- WHEN a user opens the pipeline board
- THEN a notice says six leads are on no board, and lists them on request

### Requirement: The board opens where the open leads are (REQ-RAF-021)

The board SHALL open on the default pipeline when it has open leads. Otherwise
it SHALL open on the pipeline with the most open leads. Without open leads it
SHALL open on the default pipeline, or the first pipeline.

#### Scenario: The default pipeline is empty

- GIVEN a default pipeline without open leads and another pipeline with seven
- WHEN a user opens the pipeline board
- THEN the board shows the pipeline with seven leads
