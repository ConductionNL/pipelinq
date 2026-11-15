# pos-payment-methods Specification

## Purpose
Payment methods and payment providers on a POS transaction: the provider card, its status, and completing, retrying and reversing a payment.

## Requirements

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

### Requirement: A cashier reads the payment methods of the active providers

`GET /api/pos-payments/methods` SHALL answer, to any signed-in user, one entry per method of every active payment
provider: the provider, its label, its type (`online` or `terminal`) and the method. It SHALL carry no credential and no
other provider configuration. A provider's methods SHALL be the `methods` list in its configuration, else the default list
for that provider. The provider configuration endpoints SHALL stay admin-only. The till's payment-method dropdown SHALL read
this endpoint, and SHALL word its hint by the provider's type.

#### Scenario: A cashier sees the card terminal

- GIVEN CCV is an active provider and the cashier is not an admin
- WHEN the cashier opens the payment-method dropdown on a transaction
- THEN the dropdown offers "CCV: Card"
- AND choosing it shows "The customer pays at the CCV card terminal."

#### Scenario: An inactive provider is not offered

- GIVEN Stripe is configured but not active
- WHEN the cashier opens the dropdown
- THEN no Stripe method is offered

### Requirement: A payment is reversed only with a reason

`POST /api/pos-payments/{id}/refund` SHALL refuse a reversal whose reason is empty or only spaces with 422, before the
provider is asked, because the reason is written to the reversal and read back in the tax audit trail.

#### Scenario: No reason

- GIVEN a manager reverses a settled payment
- WHEN the reason is empty
- THEN the server answers 422 "A reason is required to reverse a payment" and nothing is reversed
