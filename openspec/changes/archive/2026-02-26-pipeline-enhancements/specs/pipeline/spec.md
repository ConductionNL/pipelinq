# Pipeline Enhancements Specification (Delta)

## Purpose

Add list view toggle and quick actions on pipeline cards to complete MVP pipeline features.

## ADDED Requirements

### Requirement: Pipeline View Toggle [MVP]

The pipeline page MUST provide a toggle between kanban board view and list table view.

#### Scenario: Toggle between views
- WHEN the user clicks the view toggle
- THEN the pipeline MUST switch between kanban (columns) and list (table) view
- AND the selected view MUST persist during the session

#### Scenario: List view content
- WHEN the user is in list view
- THEN items MUST be displayed in a table with columns: title, entity type badge, stage, assignee, value (leads), due date, priority
- AND rows MUST be clickable to navigate to detail views
- AND the table MUST be sortable by clicking column headers

#### Scenario: List view preserves filters
- WHEN the user switches from kanban to list view
- THEN the same pipeline selection and entity type filter MUST be preserved

### Requirement: Pipeline Card Quick Actions [MVP]

Pipeline cards MUST support quick actions for moving between stages and assigning users without opening the detail view.

#### Scenario: Quick stage change
- WHEN the user clicks the stage action on a card
- THEN a dropdown MUST show available stages from the current pipeline
- AND selecting a stage MUST move the item to that stage
- AND the board MUST refresh to reflect the change

#### Scenario: Quick assign
- WHEN the user clicks the assign action on a card
- THEN a dropdown MUST show available users
- AND selecting a user MUST assign the item to that user
- AND the card MUST update to show the new assignee

#### Scenario: Actions don't navigate
- WHEN the user interacts with quick actions
- THEN the card MUST NOT navigate to the detail view
