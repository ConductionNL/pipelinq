# pos-cash-management Delta: pos-shift-summary

## ADDED Requirements

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
