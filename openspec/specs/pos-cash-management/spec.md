# pos-cash-management Specification

## Purpose
Cash shifts in the POS: opening, counting and closing a shift, and reviewing and approving a cash difference as declared facts cards and actions. The requirements with REQ ids are synced from the archived change `2026-06-14-pos-cash-management`.

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

### Requirement: Declare Opening Float (REQ-CCM-001)

The system MUST allow a POS operator to declare a starting cash amount at the beginning of a shift.

#### Scenario: Open a new shift with float

- GIVEN the cashier arrives at the start of a shift
- WHEN the cashier taps "Openen shift" and enters €100.00 as the opening float
- THEN the system MUST create a `cashShift` object with `status: open`, `floatAmount: 100.00`, `floatAt: <now>`, and `operator: <logged-in user>`
- AND the shift MUST be immediately available for sales transactions

#### Scenario: Float declaration is non-negotiable

- GIVEN a cashier attempts to open a shift without declaring a float amount
- WHEN they tap "Openen shift" without entering an amount
- THEN the form MUST show a validation error: "Openingsbedrag verplicht"
- AND the shift creation MUST be blocked

### Requirement: Record Mid-Shift Drops (REQ-CCM-002)

The system MUST allow recording cash removals during a shift (e.g., deposit with manager, bank run).

#### Scenario: Record a mid-shift drop

- GIVEN a shift is open with €100.00 float and €500.00 in sales
- WHEN the cashier taps "Geld verwijderen", enters €250.00, selects reason "manager-deposit", and confirms
- THEN the system MUST create a `cashDrop` object linked to the shift
- AND the drop MUST be immediately visible in the shift's drops list
- AND future diff calculations MUST account for this drop

#### Scenario: Multiple drops in a shift

- GIVEN a shift with one €250.00 drop at 13:00
- WHEN a second €150.00 drop is recorded at 18:00
- THEN both drops MUST appear in the shift's drop list
- AND the diff calculation MUST sum both drops: `dropsTotal = €250.00 + €150.00`

#### Scenario: Drop amount validation

- GIVEN a shift with €100.00 float and no drops yet
- WHEN the cashier tries to record a €500.00 drop
- THEN the form MAY warn: "Drop amount (€500) exceeds float + sales estimate; check amount"
- BUT the system MUST allow the drop (override allowed for exceptional cases like cash transfers)

### Requirement: Perform Blind Count at Close (REQ-CCM-003)

The system MUST allow recording a physical count of cash in the drawer without prior knowledge of expected amount (blind count).

#### Scenario: Close shift and perform blind count

- GIVEN a shift is open
- WHEN the cashier taps "Shift afsluiten en tellen"
- THEN the system MUST present a count entry form with a single text input: "Geteld bedrag"
- AND no hints or expected amounts MUST be displayed on the form
- WHEN the cashier enters €625.50 and confirms
- THEN the system MUST create a `cashCount` object with `amount: 625.50`, `countedAt: <now>`, `countedBy: <cashier>`
- AND shift status MUST change from `open` to `closed`

#### Scenario: Blind count with denomination breakdown (optional)

- GIVEN the count entry form is displayed
- WHEN the cashier chooses to enter a detailed denomination breakdown (€100 bills, €50 bills, coins, etc.)
- THEN the system MAY display optional denomination input fields
- AND the total MUST be computed from the breakdown
- AND if a manual total is also entered, the system MUST validate they match

#### Scenario: Count rejected by manager

- GIVEN a count of €625.50 has been recorded
- WHEN a manager reviews the variance and finds it unacceptable (outside tolerance)
- THEN the manager MUST be able to reject the count and reopen the shift for a recount

### Requirement: Calculate and Display Variance (REQ-CCM-004)

The system MUST automatically calculate the difference between expected cash (float + sales − drops) and actual cash (count), and display it to the shift manager.

#### Scenario: Variance calculation with no drops

- GIVEN a shift with €100.00 float, €500.00 in confirmed sales, and no drops
- WHEN the blind count is €600.00
- THEN the system MUST calculate: `expected = 100 + 500 − 0 = €600.00`, `actual = €600.00`, `diff = 0.00`, `percentage = 0%`
- AND the diff panel MUST display all calculated values

#### Scenario: Variance calculation with drops

- GIVEN a shift with €100.00 float, €800.00 in sales, €250.00 dropped, and count of €650.00
- WHEN the count is recorded
- THEN the system MUST calculate: `expected = 100 + 800 − 250 = €650.00`, `diff = 0.00`
- AND the diff status MUST be `pending` (awaiting approval)

#### Scenario: Shortage within tolerance

- GIVEN a shift with expected €100.00 (or similar small amount) and actual €98.50 (1.5% shortage)
- WHEN the count is recorded
- THEN the system MUST detect `|diffPercentage| ≤ 2%` (default tolerance)
- AND display: "Verschil binnen tolerantie (1.5%); klaar voor goedkeuring"
- AND set `withinTolerance: true`

#### Scenario: Overage beyond tolerance

- GIVEN a shift with expected €500.00 and actual €515.00 (3% overage)
- WHEN the count is recorded
- THEN the system MUST detect `|diffPercentage| > 2%`
- AND display: "Verschil BUITEN tolerantie (3.0%); manager-goedkeuring vereist"
- AND require explicit manager approval before posting to Shillinq

#### Scenario: Division by zero in variance calculation

- GIVEN a shift with €0.00 float (anomalous but possible)
- WHEN the count is recorded
- THEN the system MUST handle division by zero gracefully
- AND either mark `diffPercentage: null` or compute it as infinity and display: "Percentageberekening N/A (verwacht bedrag is €0)"

### Requirement: Manager Approval Workflow (REQ-CCM-005)

The system MUST allow shift managers to review and approve cash variances, and reject them if further investigation is needed.

#### Scenario: Auto-approve variance within tolerance

- GIVEN a variance of €1.50 (0.5% on €300 expected) is within the 2% tolerance
- WHEN the manager opens the shift detail
- THEN the system MAY display: "Variance auto-approved (within tolerance)"
- AND the diff status MAY auto-transition to `approved` without explicit action
- OR the system MUST provide an "Goedkeuren" button for explicit approval

#### Scenario: Manager approves variance outside tolerance

- GIVEN a variance of €10.00 (2.5% shortage) is outside tolerance
- WHEN the manager taps "Goedkeuren" after investigation
- THEN the system MUST set diff status to `approved`, `approvedBy: <manager>`, `approvedAt: <now>`
- AND emit the `pipelinq.CashDiff.confirmed` CloudEvent to Shillinq
- AND the shift closes with reconciliation complete

#### Scenario: Manager rejects variance and reopens shift

- GIVEN a variance showing €50.00 overage (10%), suspected data error
- WHEN the manager taps "Afwijzen" and enters reason "Recount required; possible scanner error"
- THEN the system MUST set diff status to `rejected`
- AND revert shift status from `closed` back to `open`
- AND create a task for the cashier: "Hercount verplicht; vorige telling afgewezen"
- AND the shift MUST be re-openable for another count attempt

### Requirement: CloudEvent Emission on Diff Confirmation (REQ-CCM-006)

The system MUST emit a structured CloudEvent when a variance is approved so that Shillinq can post an accounting adjustment.

#### Scenario: CloudEvent published on approval

- GIVEN a variance of €3.25 is approved by manager1 at 2026-05-21T22:00:00Z
- WHEN the manager taps "Goedkeuren"
- THEN the system MUST emit a CloudEvent with:
  - `type: "pipelinq.CashDiff.confirmed"`
  - `source: "pipelinq/cashShift"`
  - `subject: "<shift-reference>"`
  - `data.shift_id`, `data.drawer`, `data.diff_amount`, `data.diff_percentage`, `data.expected_amount`, `data.actual_amount`, `data.approved_by`, `data.approved_at`
- AND Shillinq MUST receive the event (subscription endpoint configured)

#### Scenario: Shillinq posts adjustment journal entry

- GIVEN Shillinq receives the `pipelinq.CashDiff.confirmed` event with `diff_amount: €3.25`
- WHEN Shillinq processes the event
- THEN Shillinq MUST create a GL adjustment entry:
  - Account: Cash (debit/credit based on sign of diff_amount)
  - Amount: €3.25
  - Reference: shift reference + cash diff
- AND the entry MUST be dated and time-stamped
- AND linked back to the shift for traceability

### Requirement: Shift List and Filtering (REQ-CCM-007)

The system MUST display a list of shifts with filtering and search capabilities.

#### Scenario: List all shifts with status filter

- GIVEN the "Kassalade" → "Shifts" view is open
- WHEN the user filters by status = "open"
- THEN only shifts with `status: open` MUST be displayed
- AND the list MUST show: Reference, Drawer, Operator, Float, Status, Opened time

#### Scenario: Date range filter

- GIVEN the shift list is displayed
- WHEN the user selects date range 2026-05-20 to 2026-05-21
- THEN only shifts with `floatAt` in that range MUST be displayed

#### Scenario: Search by reference

- GIVEN the shift list is displayed
- WHEN the user enters "SHIFT-2026-0521" in the search box
- THEN the system MUST filter shifts where reference matches (contains match)

