# pos-payment-methods Specification (delta)

## ADDED Requirements

### Requirement: A failed payment can be tried again from the transaction

The POS transaction page SHALL show the payment provider, method, session and payment status when a provider handled the
payment. While the payment status is `failed` it SHALL offer Try again, which starts the payment again with the provider
and method the transaction holds. While it is `pending` it SHALL offer Complete. For an admin it SHALL offer Reverse on a
settled or captured payment of a confirmed or settled transaction, asking for the reason.

#### Scenario: A failed card payment is tried again

- GIVEN a confirmed transaction whose CCV card payment failed
- WHEN the cashier chooses Try again
- THEN the payment is started again with CCV and card
- AND the card shows the new payment status
