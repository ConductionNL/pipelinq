# pos-receipt-transaction-card Delta

## ADDED Requirements

### Requirement: The receipt's transaction card reads as the board

The transaction card on a POS receipt SHALL show Identifier, Status, Cashier, Till, Price display, Client, Created and
Settled in that order, three to a row. Created and Settled SHALL be read-only. The Totals side card SHALL draw each
label beside its value, at the start of the column. The receipt card's print and email buttons SHALL be tonal pills.

#### Scenario: Board PqKassabon

- **WHEN** a user opens a settled POS transaction
- **THEN** the Transactie card reads Kenmerk, Status, Kassamedewerker, Kassa, Prijsweergave, Klant, Aangemaakt, Afgerekend
- **AND** the Totalen rows sit close together with their labels at the left
- **AND** Bon printen and Bon mailen are light-blue pills
