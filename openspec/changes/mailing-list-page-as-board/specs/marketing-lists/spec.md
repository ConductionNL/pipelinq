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

### Requirement: A list's sign-ups are managed from the list

The mailing list page SHALL offer "Manage sign-ups": send an address the list's own confirmation link, which leaves the
person pending until they open it, and take a member who is a known contact off this list.

#### Scenario: A new sign-up from the list

- **WHEN** a colleague sends the confirmation link to nieuw@ondernemer.example
- **THEN** the list's confirmation mail goes out and the address shows as waiting for confirmation once it is stored

### Requirement: The subscribers can be sorted and filtered

The Subscribers table SHALL sort on every column, turning the order around on a second press, and SHALL filter every
column on the values its rows hold.

### Requirement: The header names the confirmed subscribers and who made the list

The mailing list page's header line SHALL read the count of confirmed subscribers, who created the list and when.
