# tonal-card-actions-pos-and-lead Specification

## Purpose
The actions inside the cards of the cash shift and lead pages draw as tonal pills, so they read as secondary to the page's primary action.

## Requirements

### Requirement: Card actions on the shift and lead pages are tonal pills

The cash difference's Approve and Reject, the drops card's Add drop and the lead's Add product SHALL draw as tonal
pills, as boards PqKassadienst and PqLead draw them.

#### Scenario: Board PqKassadienst

- **WHEN** a manager opens a counted shift with a pending difference
- **THEN** Goedkeuren and Afwijzen are light-blue pills
- **AND** Afstorting toevoegen in the Afstortingen card is a light-blue pill

#### Scenario: Board PqLead

- **WHEN** a user opens a lead
- **THEN** Product toevoegen in the products card is a light-blue pill
