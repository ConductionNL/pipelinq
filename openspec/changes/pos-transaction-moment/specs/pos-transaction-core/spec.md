# pos-transaction-core: delta for pos-transaction-moment

## ADDED Requirements

### Requirement: The transactions list reads when the sale happened

The transactions list MUST show and filter each transaction by its moment. The moment is its confirmation time, else its parking time; the column falls back to the object's creation time for a draft. The "Vandaag" lens MUST list the transactions whose moment is today.

#### Scenario: a seeded sale

- GIVEN POS-2026-0871 was confirmed 25 days before the set was installed
- WHEN the transactions list opens
- THEN its Aangemaakt column reads that date, not the install date
- AND it is not in the Vandaag lens
