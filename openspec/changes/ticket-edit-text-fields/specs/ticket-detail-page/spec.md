## ADDED Requirements

### Requirement: The ticket edit dialog draws its long texts as multi-line fields

The ticket's edit dialog MUST draw Description as a three-line text field and the message to the customer as a two-line text field, each across both columns of the form, and the Title across both columns above the Description.

#### Scenario: Pieter edits the street party request
- GIVEN ticket PQ-2026-0417 with a two-sentence description
- WHEN Pieter opens Edit
- THEN Title spans the dialog, the whole description shows in a three-line field under it, and "Message to the customer" is a two-line field
