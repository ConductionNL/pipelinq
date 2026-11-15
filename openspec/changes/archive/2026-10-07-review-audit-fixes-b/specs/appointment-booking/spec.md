# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: A saved composition step shows its product name (REQ-RAF-040)

After a user saves a service's composition steps, every step with a product
SHALL show the product's name at once. A product whose name could not be read
SHALL be asked for again on the next change, not shown as its uuid until reload.

#### Scenario: A step is saved with a product

- GIVEN a service in step editing
- WHEN the user picks "Knipbeurt" for a step and saves
- THEN the step table shows "Knipbeurt"
