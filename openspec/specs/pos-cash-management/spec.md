# pos-cash-management Specification

## Purpose
Cash shifts in the POS: opening, counting and closing a shift, and reviewing and approving a cash difference as declared facts cards and actions.

## Requirements

### Requirement: The shift shows its latest cash difference

The cash shift page SHALL show the shift's latest cash difference: expected, counted and difference as money, the
percentage, whether it is within tolerance, its review status, the reviewer and a rejection reason. Before the shift is
counted it SHALL say so. While the difference is pending an admin SHALL be able to approve it, or reject it with a
reason, which reopens the shift for a recount.

#### Scenario: An admin approves a pending difference

- GIVEN a closed shift whose count left a pending difference of 2.50
- WHEN an admin chooses Approve
- THEN the difference is approved and the card shows Approved and the reviewer

### Requirement: A shift is counted only with an amount

`POST /api/pos-shifts/{id}/count` SHALL refuse a count without a numeric amount with 422 "Enter the counted amount.",
and the open shift's primary button SHALL be Close and count shift, asking for the amount and optional notes.

#### Scenario: No amount

- GIVEN an open shift
- WHEN a count is sent without an amount
- THEN the server answers 422 and the shift stays open

### Requirement: A shift is opened from the cash drawer list

The cash drawer list SHALL offer Open shift, asking for the drawer and the opening amount (both required), a reference and
notes, SHALL post them to the open-shift endpoint, and SHALL open the new shift.

#### Scenario: Opening a shift

- GIVEN the cash drawer list
- WHEN the cashier opens a shift on drawer "Balie 2" with 100.00
- THEN the new shift's page opens

### Requirement: A shift summary reads its drops and difference

The app SHALL answer, per shift, its number of drops, their total, its newest cash difference (the pending one, else the
last), the margin of that difference and an outcome word: `open` for an open shift, `counted` without a difference,
`rejected`, `approved` for an approved exact count, else `within the margin` or `outside the margin`.

#### Scenario: A shortage outside the margin

- **GIVEN** a closed shift with two drops of € 200 and € 50 and a pending difference of -12,40 outside a 2% margin
- **WHEN** the shifts list asks for the outcome and the drops
- **THEN** it reads `outside the margin` and 2

### Requirement: A shift page names its sales and Z report

The shift summary SHALL list the sales booked in the shift and the Z reports whose till starts the shift's drawer name on
the shift's day.

#### Scenario: Balie 3 on 6 October

- **GIVEN** a shift on drawer "Balie 3, stadskantoor" opened 6 October and a Z report for till "Balie 3" on 6 October
- **WHEN** the shift page reads its summary
- **THEN** the Z report is listed
