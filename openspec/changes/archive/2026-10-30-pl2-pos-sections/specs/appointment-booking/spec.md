# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: The booking context names the service and the customer

The booking page SHALL show the booking's service and customer by name (the customer a contact or a client), its deposit
with when it was paid, and a link to the booking it was rescheduled from when there is one.

#### Scenario: A rescheduled booking

- GIVEN a booking rescheduled from an earlier one
- THEN the Service and customer card names the service and the customer and links to the earlier booking
