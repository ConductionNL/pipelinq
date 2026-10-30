# pipeline: delta for pipeline-board-toolbar

## ADDED Requirements

### Requirement: The pipeline board draws the board toolbar in the board look

In the board look the pipeline board MUST draw the board toolbar: the chips, Filter and the view switch, then the pipeline select, the search, Pipeline settings and Columns, then the hint and the active filters with Clear all.

#### Scenario: the active filters

- GIVEN the Verkoop pipeline board in the board look
- WHEN the user chooses the My leads chip
- THEN the active filters read "Pipeline: Verkoop" and "My leads", and Filter shows 2

#### Scenario: clear all

- GIVEN the board with the My leads chip and a search
- WHEN the user chooses Clear all
- THEN the board shows every open lead of the pipeline it opens with
