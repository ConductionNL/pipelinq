# pos-cash-management Specification (delta)

## ADDED Requirements

### Requirement: A shift is opened from the cash drawer list

The cash drawer list SHALL offer Open shift, asking for the drawer and the opening amount (both required), a reference and
notes, SHALL post them to the open-shift endpoint, and SHALL open the new shift.

#### Scenario: Opening a shift

- GIVEN the cash drawer list
- WHEN the cashier opens a shift on drawer "Balie 2" with 100.00
- THEN the new shift's page opens
