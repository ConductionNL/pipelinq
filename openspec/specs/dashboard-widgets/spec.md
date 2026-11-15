# dashboard-widgets Specification

## Purpose
How the cards and tables on pipelinq's dashboards and detail pages look and behave: their heads, rows, avatars and subtitles.

## Requirements

### Requirement: The ticket contact moments table sorts and filters from its heads

The contact moments table on a ticket SHALL draw a sort button and a filter button in every data column head.

#### Scenario: Board PqTicketContactmomenten

- **WHEN** an agent opens the Contact moments tab of a ticket
- **THEN** every head carries a sort and a filter button and the head row is 42.5px tall

### Requirement: The z-report tables draw the board table rows

The VAT breakdown and payment method tables of a z-report SHALL draw 14px text on the normal line, 12px row padding
with 18px outer insets, and a rule under every row.

#### Scenario: Board PqZRapport

- **WHEN** a manager opens a z-report
- **THEN** the VAT and payment rows read 14px with a rule under each row

### Requirement: Card tables sort and filter from their heads

The loyalty tier table, the operational client overview, the quote lines list and the till's purchase history and
payments tables SHALL draw a sort button and a filter button in every data column head, as the boards draw them.

#### Scenario: Board PqLoyaliteit

- **WHEN** the manager opens Loyalty reporting
- **THEN** the "Tier" and "Accounts" heads each carry a sort button and a filter button
- **AND** the head row is 42.5px tall

#### Scenario: Board PqKassa

- **WHEN** the cashier opens a sale for a known customer
- **THEN** the purchase history and payments heads each carry a sort button and a filter button
- **AND** sorting or filtering reorders or narrows the rows shown, without a new request

### Requirement: The queue team card draws the small avatar

The "Team today" card beside the queue SHALL draw each colleague's initials in the 32px avatar, as board PqWachtrij
and dossiq's team workload card draw it.

#### Scenario: Board PqWachtrij

- **WHEN** a coordinator opens the queue in the simple structure
- **THEN** each colleague in "Team today" carries a 32px avatar with their initials

### Requirement: The sales overview cards carry the board subtitles and end at their content

The sales overview SHALL show the subtitle "Won deals" under the revenue per month, revenue by source and largest
customers titles, and every card but the revenue chart SHALL end at its content with the cards under it moving up.

#### Scenario: Board PqVerkoopoverzicht

- **WHEN** a sales lead opens the sales overview
- **THEN** "Revenue per month" reads "Won deals" under its title
- **AND** "Closing soon" starts right under the revenue chart, and "Recent won / lost" right under the open pipeline
