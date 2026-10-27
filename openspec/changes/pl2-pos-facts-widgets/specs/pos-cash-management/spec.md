# pos-cash-management Specification (delta)

## ADDED Requirements

### Requirement: The shift shows its latest cash difference

The cash shift page SHALL show the shift's latest cash difference: expected, counted and difference as money, the
percentage, whether it is within tolerance, its review status, the reviewer and a rejection reason. Before the shift is
counted it SHALL say so. While the difference is pending an admin SHALL be able to approve it, or reject it with a
reason, which reopens the shift for a recount.

#### Scenario: An admin approves a pending difference

- GIVEN a closed shift whose count left a pending difference of 2.50
- WHEN an admin chooses Approve
- THEN the difference is approved and the card shows Approved and the reviewer
