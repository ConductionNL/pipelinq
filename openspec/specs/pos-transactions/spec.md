# pos-transactions Specification

## Purpose
The POS sale: the new-sale page with its lines, the customer and a receipt preview, and the check-out through a payment dialog.

## Requirements

### Requirement: The sale page reads as the board
The new-sale page SHALL be a record page with a Draft pill, the Transactions breadcrumb and "<register> · cashier <name>", the cards Lines, Transaction and (with a customer) Purchase history, and beside them Totals, Customer and Preview of the receipt. A scanned or typed barcode SHALL add the product the barcode lookup finds, or one more of it.

#### Scenario: A barcode adds a product
- GIVEN a product with barcode 8712345000124
- WHEN the cashier scans it twice
- THEN the sale has one line for that product with quantity 2

### Requirement: A payment can be split
Checking out SHALL open the Payment received dialog, which splits the total over the customer's loyalty points, gift cards and the rest at the chosen payment method, and allows checking out only when the parts add up to the total.

#### Scenario: Points, a gift card and the rest
- GIVEN a sale of € 96,41, a customer with 340 points at € 0,05 a point and a gift card with € 25,00
- WHEN the cashier uses 200 points and adds the gift card
- THEN the dialog says "Payment closes: € 10,00 + € 25,00 + € 61,41 is € 96,41"
- AND checking out spends the points, redeems the gift card and books three tenders

### Requirement: Loyalty points can pay part of a sale
POST /api/loyalty/accounts/{accountId}/pay SHALL debit the points against the sale and return their amount at the programme's point value, and SHALL refuse more points than the account holds or a programme without a point value.

#### Scenario: Spending points
- GIVEN an account with 340 points in a programme where a point is worth € 0,05
- WHEN 200 points are spent on sale tx-1
- THEN 200 points are debited with tx-1 as the source and the answer is € 10,00 with 140 points left

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
