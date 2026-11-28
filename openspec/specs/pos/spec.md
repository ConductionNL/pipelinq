# pos Specification

## Purpose
The POS cash shift page: which actions the header offers at each stage of a shift, and how they are drawn.

## Requirements

### Requirement: A counted shift offers Approve difference as a secondary button

The cash shift page SHALL offer "Approve difference" on a counted shift with a pending difference as a secondary header
button with its icon, not as the page's primary button.

#### Scenario: Board PqKassadienst

- **WHEN** an administrator opens a closed shift whose difference is pending
- **THEN** the header shows "Verschil goedkeuren" as a white button with a check icon
