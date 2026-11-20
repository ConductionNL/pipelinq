# pos-refund-return Specification (delta)

## ADDED Requirements

### Requirement: Refund totals show what the server booked

The refund page SHALL show the refund's amount excluding VAT and its VAT as the server stored them on the refund, and the
method the money went back to, and SHALL NOT recompute them in the browser.

#### Scenario: A booked refund

- GIVEN a refund the server stored with 10.33 excl. VAT and 2.17 VAT
- THEN the Refund totals card shows those two amounts
