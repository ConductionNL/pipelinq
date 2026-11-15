# lead-management Specification (delta)

## ADDED Requirements

### Requirement: Every lead sits in a pipeline stage (REQ-PNT-010)

When a lead is created without a stage, the system SHALL place it in the first
stage that is not closed, by stage order, of its pipeline. When it also has no
pipeline, the system SHALL use the default lead pipeline, or the first lead
pipeline when none is marked default. The system SHALL set `stageOrder` and
`stageEnteredAt` with the stage. This SHALL hold for every way a lead is
created: the form, the API, flows and imports. A repair step SHALL place the
stored leads that have no stage, with their creation time as entry time.

#### Scenario: A website enquiry becomes a lead in the first stage

- GIVEN the default sales pipeline has the stages New, Contacted and Won
- WHEN the enquiry flow creates a lead with that pipeline and no stage
- THEN the lead is stored in stage New with stage order 1

#### Scenario: Stored leads without a stage are repaired

- GIVEN a stored lead on the sales pipeline without a stage, created on 1 September
- WHEN the administrator runs the maintenance repair
- THEN the lead is in stage New, entered on 1 September

#### Scenario: A lead keeps the stage it was given

- GIVEN a new lead created in stage Contacted
- WHEN it is saved
- THEN it stays in Contacted and gets that stage's order
