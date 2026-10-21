# pos-payment-methods Specification (delta)

## ADDED Requirements

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

