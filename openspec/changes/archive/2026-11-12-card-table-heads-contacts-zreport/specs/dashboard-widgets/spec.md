# dashboard-widgets Delta: card-table-heads-contacts-zreport

## ADDED Requirements

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
