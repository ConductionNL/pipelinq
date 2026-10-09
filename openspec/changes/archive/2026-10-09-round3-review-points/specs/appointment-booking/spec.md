## ADDED Requirements

### Requirement: A new service keeps the product of its steps (REQ-R3-004)

When a user creates a service with composition steps, every step SHALL be saved
with the product, quantity and unit the user picked.

#### Scenario: Create a service with a product step

@e2e exclude Asserted in tests/vitest/serviceFormSteps.spec.js on the mounted ServiceForm.
- GIVEN the New service page
- WHEN the user adds a step with the product "Implementatie", 8 hours, and saves
- THEN the saved step SHALL carry the product's id, the quantity 8 and the unit `hour`
