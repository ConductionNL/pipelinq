# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: The booking page names the customer and the service (REQ-RF-020)

The booking page's data block SHALL show the customer's name and the
service's name, not their ids. The customer SHALL be looked up as a contact
first and as a client second. While a name loads the block SHALL show a
placeholder. When no object is found the id SHALL stay visible.

#### Scenario: A booking shows names

- GIVEN a booking for contact Jan Jansen and service Haircut
- WHEN the user opens the booking
- THEN the data block shows "Jan Jansen" as customer and "Haircut" as service
