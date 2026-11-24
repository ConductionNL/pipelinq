# client-bulk-changes Specification (delta)

## Purpose

A user changes one field on many clients or contacts at once, sees the effect
before it is written, and can undo it while OpenRegister's reversal window is
open. From pipelinq matrix rows `bulk-edit` and `clients-undo-bulk-change`.

## ADDED Requirements

### Requirement: Clients and contacts can be changed in bulk (REQ-CBE-001)

The Clients and Contacts lists SHALL let a user select records and choose
Change a field. The system SHALL write the change through an OpenRegister bulk
job with action `openregister:set-properties`, and SHALL NOT write any record
before the user confirms the preview.

#### Scenario: A sales manager moves fifty clients to a new industry

- GIVEN a sales manager on the Clients list with fifty clients filtered and selected
- WHEN they choose Change a field, pick Industry, type Healthcare and press Preview
- THEN the dialog says 50 records will change and nothing in the list has changed yet
- AND after they press Confirm, the Industry column shows Healthcare on all fifty

#### Scenario: Records that already carry the value are skipped

- GIVEN five selected contacts, two of which already have role Buyer
- WHEN a user previews setting role to Buyer
- THEN the dialog says 3 records will change and 2 already have this value

#### Scenario: A refused bulk change writes nothing

- GIVEN a selection larger than OpenRegister's bulk ceiling
- WHEN a user presses Preview
- THEN the dialog shows OpenRegister's refusal reason
- AND no client is changed

### Requirement: A user can undo their own bulk change (REQ-CBE-002)

The system SHALL list a user's own bulk changes on clients and contacts on a
Bulk changes page. While a change is inside OpenRegister's reversal window, the
page SHALL offer Undo, which previews the reversal and writes the prior values
back only on confirmation. After the window the page SHALL show until when it
could be undone and SHALL NOT offer Undo.

#### Scenario: A sales manager undoes a mistaken change the next day

- GIVEN yesterday's bulk change that set Industry to Healthcare on fifty clients
- WHEN the sales manager opens Bulk changes and presses Undo on that row, then Confirm
- THEN the fifty clients show their earlier industries again on the Clients list

#### Scenario: A change older than the window cannot be undone

- GIVEN a bulk change made ten days ago
- WHEN the user opens Bulk changes
- THEN that row shows the date it stopped being reversible
- AND no Undo action is offered on it
