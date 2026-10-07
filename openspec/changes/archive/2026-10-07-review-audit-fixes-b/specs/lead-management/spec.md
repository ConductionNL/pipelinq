# lead-management Specification (delta)

## ADDED Requirements

### Requirement: A reseed re-links demo leads to the new pipeline (REQ-RAF-010)

When the demo seed finds a demo object that already exists, it SHALL keep it,
and SHALL point its `pipeline` at the demo pipeline this run resolved when the
two differ. Nothing else on the object SHALL change.

#### Scenario: The demo pipeline was deleted

- GIVEN six demo leads on a deleted demo pipeline
- WHEN the administrator loads the demo data again
- THEN a new demo pipeline exists and the six leads sit on it, in their own stage

### Requirement: A repair step moves leads off a deleted pipeline (REQ-RAF-011)

A repair step SHALL move every lead whose `pipeline` names no existing pipeline
to the default lead pipeline. An open lead SHALL go to the first open stage, a
won lead to the won stage and a lost lead to the closed stage that is not won.
The step SHALL write as the signed-in user, or as the pipelinq system account
when nobody is signed in, with OpenRegister's access checks on. When no
pipeline can be read, the step SHALL move nothing.

#### Scenario: An open lead on a deleted pipeline

- GIVEN an open lead on a pipeline that was deleted
- WHEN the maintenance repair runs
- THEN the lead is on the default pipeline in its first open stage

#### Scenario: A won lead stays won

- GIVEN a won lead on a pipeline that was deleted
- WHEN the maintenance repair runs
- THEN the lead is in the default pipeline's won stage

### Requirement: Tender is a default lead source (REQ-RAF-012)

The default lead sources SHALL include `tender`, and every lead source the demo
data uses SHALL be a default lead source.

#### Scenario: A tender lead is edited

- GIVEN the demo lead "Intranet migratie Zonnedael" with source tender
- WHEN a user opens its edit form
- THEN tender is one of the offered sources

### Requirement: The qualification score is calculated, not typed (REQ-RAF-013)

The lead edit dialog SHALL NOT offer the qualification score. The lead page
SHALL show it read-only, with a description that says it is calculated when the
lead is saved.

#### Scenario: A user edits a lead

- GIVEN a lead with score 65
- WHEN the user opens Edit
- THEN the form has no qualification score field
