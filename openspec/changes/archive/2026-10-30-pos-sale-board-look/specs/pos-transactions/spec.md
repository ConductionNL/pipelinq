# pos-transactions (delta)

## ADDED Requirements

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
