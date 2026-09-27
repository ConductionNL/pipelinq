# pipeline-stage-limits Specification (delta)

## Purpose

A pipeline stage can hold at most a set number of items, and every screen
respects it. From pipelinq matrix row `pipeline-stage-limit`.

## ADDED Requirements

### Requirement: A stage can carry a limit (REQ-PSL-001)

The pipeline editor SHALL let an administrator set an optional limit on an open
stage. The board SHALL show each limited stage's count against its limit and
SHALL mark a stage that is full.

#### Scenario: A sales manager caps Proposal at eight

- GIVEN a sales manager editing the Sales pipeline
- WHEN they set Limit 8 on the Proposal stage and save
- THEN the board's Proposal column header shows the number of deals in it out of 8

### Requirement: No write puts an item into a full stage (REQ-PSL-002)

The system SHALL refuse any create or update, from any screen or API, that would
put an item into a stage already at its limit, and SHALL say which stage is full.
Moving an item out of a stage, or editing it without changing its stage, SHALL
never be refused.

#### Scenario: A drop into a full column is refused and explained

- GIVEN the Proposal stage holds 8 of 8 deals
- WHEN a sales rep drags a ninth deal onto Proposal on the board
- THEN a message says stage Proposal is full, 8 of 8
- AND the deal stays in its previous column

#### Scenario: The edit form is held to the same limit

- GIVEN the Proposal stage is full
- WHEN a sales rep sets a deal's stage to Proposal in its edit form and saves
- THEN the form shows that stage Proposal is full and the deal keeps its old stage

#### Scenario: Moving out of a full stage works

- GIVEN the Proposal stage is full
- WHEN a sales rep drags a deal from Proposal to Negotiation
- THEN the deal moves and Proposal shows 7 of 8
