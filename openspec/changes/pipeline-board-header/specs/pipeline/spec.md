# pipeline: delta for pipeline-board-header

## ADDED Requirements

### Requirement: The pipeline board header offers Download

The pipeline board header MUST offer Download, which writes the leads the board shows as a CSV file, beside the buildiq square and New lead.

#### Scenario: download the board

- GIVEN the Verkoop pipeline board with 14 open leads
- WHEN the user chooses Download
- THEN a CSV with those 14 leads is saved
