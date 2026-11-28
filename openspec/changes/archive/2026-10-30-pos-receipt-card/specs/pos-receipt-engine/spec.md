# pos-receipt-engine Delta: pos-receipt-card

## ADDED Requirements

### Requirement: A transaction page shows its receipt history

The app SHALL answer, for a transaction the caller may access, the newest successful print with its printer, the
number of successful prints, the newest mail that was sent with its recipient, whether the sale prints as a receipt or an
invoice, and the invoice threshold. A failed print or mail SHALL not count.

#### Scenario: Printed twice, one failed mail

- **GIVEN** two prints at 11:40 and 11:43 and a failed mail
- **WHEN** the receipt card reads the log
- **THEN** it shows the 11:43 print, two prints and no mail
