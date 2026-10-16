## ADDED Requirements

### Requirement: Checkout splits the payment (REQ-POS-BOARD-006)
Checkout on the till MUST open a payment dialog that shows the amount due and splits it over loyalty points (when the customer has a loyalty account), gift cards (checked by number and PIN) and the rest on the till's payment method, and says when the parts add up to the amount due. Confirming it MUST save and confirm the sale, redeem each gift card and record it as a voucher tender, record the rest as a tender of the till's payment method, and settle the sale; when a payment step fails, the saved sale MUST open so its payment can be finished there.

#### Scenario: A gift card and the card terminal
@e2e exclude Split logic asserted in tests/vitest/paymentSplit.spec.js; the e2e instance has no gift cards.
- **GIVEN** a sale of € 96,41 and a gift card with € 25,00 on it
- **WHEN** the cashier adds the gift card at checkout
- **THEN** the dialog MUST take € 25,00 from the gift card and leave € 71,41 for the card terminal

#### Scenario: No more points than the amount is worth
@e2e exclude Asserted in tests/vitest/paymentSplit.spec.js.
- **GIVEN** a customer with 5000 points at 20 points a euro and € 10,00 due
- **WHEN** the cashier picks Use the maximum
- **THEN** 200 points MUST be used
