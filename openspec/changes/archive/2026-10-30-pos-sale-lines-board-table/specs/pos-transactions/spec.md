# pos-transactions (delta)

## ADDED Requirements

### Requirement: The sale lines read as a table and stay editable
A sale's lines SHALL read as a table row each (product name, the line's description under it when it differs, quantity, unit price, discount, VAT, line total). A line SHALL open its inputs from a pencil button and close them from a check button; a new empty line SHALL open for editing.

#### Scenario: A line reads and edits
- GIVEN a sale with a line for "Parkeervergunning bewoners, jaar" described "Zone Centrum, kenteken 12-ABC-3"
- THEN the row shows the product name over the description and the amounts as text
- WHEN the cashier presses the pencil
- THEN the row shows the product, description, quantity, price, discount and VAT inputs

### Requirement: The sale header and fields follow the board
The sale page header SHALL hold only Check out (no More menu). The barcode, register and notes fields SHALL carry a label above them, and the barcode field SHALL take the focus when the page opens.

#### Scenario: Labelled fields
- WHEN the cashier opens a new sale
- THEN "Scan or type a barcode", "Register", "Price display", "Tender" and "Notes" label their fields
