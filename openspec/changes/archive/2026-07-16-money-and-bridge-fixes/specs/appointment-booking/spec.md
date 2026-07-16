# appointment-booking Specification Delta

## MODIFIED Requirements

### Requirement: REQ-APT-010 Deposit-Required Bookings

The system MUST support optional deposits: bookings requiring deposits are created
with `status: "pending-deposit"`, the slot is held for 15 minutes, and on successful
payment `status` transitions to `confirmed`.

Initiating the payment session is not sufficient on its own: the portal MUST
also **forward the customer to the hosted checkout** by returning the session's
URL as `paymentRedirect` on the booking response. A booking that is created in
`pending-deposit` without a redirect can never be paid, and the 15-minute
timeout then releases the slot — losing both the booking and the deposit.

**Feature tier**: V1

#### Scenario: Deposit required booking holds slot

- **GIVEN** a Service with `requiresDeposit: true`, `depositAmount: 20.00`
- **WHEN** a customer submits the booking form
- **THEN** a Booking MUST be created with `status: "pending-deposit"`, the slot MUST be held for 15 minutes, and a payment session MUST be initiated via openconnector

#### Scenario: Customer is forwarded to the hosted checkout

- **GIVEN** a Service with `requiresDeposit: true`, `depositAmount: 20.00`
- **WHEN** a customer submits the booking form
- **THEN** the deposit session MUST be opened with the amount expressed in integer cents (20.00 EUR → 2000)
- AND the booking response's `paymentRedirect` MUST be the session's hosted checkout URL
- AND the response MUST NOT return a null `paymentRedirect` while a deposit is due and payable

#### Scenario: No redirect when no charge is due

- **GIVEN** a Service with `requiresDeposit: false` (or a deposit amount of 0)
- **WHEN** a customer submits the booking form
- **THEN** no payment session MUST be opened
- AND `paymentRedirect` MUST be null

#### Scenario: Payment provider unavailable degrades safely

- **GIVEN** a deposit-required Service and an unavailable / unconfigured openconnector PaymentService
- **WHEN** a customer submits the booking form
- **THEN** the Booking MUST still be created and preserved
- AND `paymentRedirect` MUST be null rather than a fabricated URL
- AND the failure MUST be logged, leaving the 15-minute timeout job to release the slot

#### Scenario: Slot released on payment timeout

- **GIVEN** a Booking with `status: "pending-deposit"` and payment not completed within 15 minutes
- **WHEN** 15 minutes elapse
- **THEN** the Booking MUST transition to `cancelled-by-business` and the slot MUST be released

#### Scenario: Status transitions to confirmed on payment success

- **GIVEN** a Booking with `status: "pending-deposit"` and the customer completes PSD2-compliant payment
- **WHEN** openconnector confirms payment
- **THEN** the Booking MUST transition to `confirmed`, `depositPaidAt` MUST be set, and a confirmation email MUST be sent
