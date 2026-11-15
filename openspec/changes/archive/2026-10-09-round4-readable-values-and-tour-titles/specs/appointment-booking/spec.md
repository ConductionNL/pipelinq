# appointment-booking delta: round4-readable-values-and-tour-titles

## ADDED Requirements

### Requirement: Service values read as words (REQ-APT-READABLE-VALUES)
Every enum on the appointmentService and appointmentResource schemas, and the
resource type of a service step, MUST declare `x-enum-labels` with an en and an
nl catalogue entry. The service page MUST show the cancellation policy and each
step's resource type as their label.

#### Scenario: A saved service
@e2e exclude Asserted in tests/vitest/readableValues.spec.js on the merged register, the label maps and ServiceDetail.
- **GIVEN** a service with cancellation policy `free` and a step of resource type `staff`
- **WHEN** a user opens the service page
- **THEN** the Policies card MUST read "Free" under Cancellation policy
- **AND** the composition table MUST read "Staff" under Resource type
