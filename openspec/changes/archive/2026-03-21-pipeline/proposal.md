# Pipeline & Kanban Specification

## Problem
Pipelines provide configurable kanban-style boards where leads and requests flow through ordered stages. A pipeline is comparable to Trello boards or Nextcloud Deck -- each pipeline has columns (stages) and cards (leads/requests). Both entity types can appear on the same pipeline, distinguished by visual badges. Pipelines are the primary visual workflow tool in Pipelinq.
**Standards**: Schema.org (`ItemList`, `DefinedTerm`), Industry patterns (Trello, HubSpot, Nextcloud Deck)
**Primary feature tier**: MVP (with V1 and Enterprise enhancements noted per requirement)

## Proposed Solution
Implement Pipeline & Kanban Specification following the detailed specification. Key requirements include:
- Requirement: REQ-PIPE-019: Multiple Pipelines per Organization [V1]
- Requirement: REQ-PIPE-020: Pipeline Template Creation [Enterprise]
- Requirement: REQ-PIPE-021: Stage Automation on Transition [Enterprise]
- Requirement: REQ-PIPE-022: Pipeline Filtering and Search [MVP]
- Requirement: REQ-PIPE-023: Pipeline Access Control [V1]

## Scope
This change covers all requirements defined in the pipeline specification.

## Success Criteria
#### Scenario 1: Create a pipeline with title and entity types
#### Scenario 2: Create a mixed entity pipeline
#### Scenario 3: Edit pipeline title and description
#### Scenario 4: Edit pipeline color
#### Scenario 5: Delete a pipeline with no items
