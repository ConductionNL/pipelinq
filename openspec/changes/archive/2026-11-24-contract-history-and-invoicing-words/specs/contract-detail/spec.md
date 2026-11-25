# contract-history-and-invoicing-words Delta: contract-detail

## ADDED Requirements

### Requirement: The contract history says what happened in words

The contract's History cards SHALL name a status change with the new status in the words of the status pill, a renewal
lead link as "Renewal lead created", and an invoice reference as "Sent to invoicing" with the reference. They SHALL draw
stacked, compact rows.

#### Scenario: The renewal job marks a contract as expiring

- **GIVEN** a contract whose status changed to `expiring`
- **WHEN** the reader opens the contract
- **THEN** the History card SHALL read "Status gewijzigd naar Verloopt binnenkort"

### Requirement: The contract actions read as the board

The send-to-invoicing action SHALL read "Naar facturering sturen" in Dutch. The renewal lead action SHALL draw no icon.

#### Scenario: An expiring contract

- **GIVEN** an expiring contract with a renewal lead
- **WHEN** the reader opens the contract
- **THEN** the next-step card SHALL offer "Verlengingslead openen" without an icon
