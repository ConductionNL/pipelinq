# pos-lead-framed-tables Delta

## ADDED Requirements

### Requirement: The POS and lead card lists are framed tables

The receipt lines, the shift drops and the lead products SHALL draw as framed tables in their cards. The receipt lines
and the drops SHALL read their first column as plain text.

#### Scenario: Board PqKassabon

- **WHEN** a user opens a POS receipt
- **THEN** the Regels table has a 1px frame inside the card, a white head with a rule under it and 18px outer insets
- **AND** each line's description reads as plain text, not a link

#### Scenario: Board PqKassadienst

- **WHEN** a user opens a shift
- **THEN** the Afstortingen table is framed the same way
