# appointment-booking Specification (delta)

## MODIFIED Requirements

### Requirement: A service's composition is edited in place and can name products (REQ-BSP-001)

ServiceDetail SHALL let an administrator edit the multi-step composition in
its own card, with Save and Cancel, without opening the general Edit dialog.
A step SHALL be able to name a product from the catalogue, with a quantity
and a unit (hour, day, month, year or piece). Saving SHALL expire the cached
availability as every service save does. Service information and Policies
SHALL sit side by side on a wide screen.

#### Scenario: An administrator composes the OpenWoo app service

- GIVEN products "Implementation", "Hosting" and "SLA" in the catalogue
- WHEN the administrator chooses Edit steps on the OpenWoo app service and adds three steps: Implementation 8 hours, Hosting 1 month, SLA 1 year
- AND saves the steps
- THEN the composition shows the three products with "8 hours", "1 months" and "1 years"

#### Scenario: Cancel leaves the composition as it was

- GIVEN a service with two steps
- WHEN the administrator edits the steps and chooses Cancel
- THEN the service still has its two steps

### Requirement: The booking page works in tabs and keeps no audit table (REQ-BSP-002)

BookingDetail SHALL show its timeline, resource assignments, notes and
documents as tabs inside the page grid. Notes SHALL use the notes leaf. The
page SHALL NOT render its own audit table; the timeline SHALL list the
booking's status changes.

#### Scenario: A front desk employee adds a note to a booking

- GIVEN a confirmed booking
- WHEN the employee opens the Notes tab and adds a note
- THEN the note is stored through the notes leaf, as on a lead

#### Scenario: The timeline shows a status change

- GIVEN a booking whose status went from pending deposit to confirmed
- WHEN the employee opens the Timeline tab
- THEN it lists "Status: Confirmed" at the moment of the change
