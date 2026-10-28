# marketing-lists Delta

## ADDED Requirements

### Requirement: The mailing list page is the board

The mailing list page SHALL show every list detail in its details card without a "show all" step, SHALL show History on
top of the side column with the three newest changes and no reads, SHALL draw the sign-up form and the subscriber
preview as titled cards, and SHALL head the Subscribers card with its subtitle and Refresh.

#### Scenario: PqMailinglijst

- **WHEN** a user opens a mailing list
- **THEN** the page shows the Lijstgegevens card with seven details, Geschiedenis first in the side column, and the
  Abonnees card with "Abonnees op deze lijst" and Vernieuwen in its header

### Requirement: A mailing list's subscribers read by name

The list's subscriptions endpoint SHALL give each row whose contact is a known contact person or client that record's full
name, and SHALL leave a row with no known contact as its address.

#### Scenario: A known contact and a public signup

- **GIVEN** a confirmed subscription of a client named Bakkerij De Graaf and a pending public signup
- **THEN** the first row reads "Bakkerij De Graaf" above its address and the second its address alone
