# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: Booking rows are edited with the library row editor

A resource's working hours and vacations SHALL be edited as rows in the resource form, each cell in the row, and a
row whose opening time is not before its closing time, or whose start date is after its end date, SHALL be named under
the rows and SHALL block the save. A service's steps SHALL be edited as rows with a dialog per step, the product picked
from the catalogue, and a line SHALL add the step minutes up against the service duration. A new resource or service
SHALL be made in the same form as an edit.

#### Scenario: Closing before opening

- GIVEN a resource whose second working-hours row opens at 18:00 and closes at 12:00
- WHEN the administrator saves the resource
- THEN the save is blocked with "Row 2: Open time must be before close time."

#### Scenario: A new service keeps its step's product

- GIVEN the New service form with a step of 480 minutes, product Implementation, 8 hours
- WHEN the administrator saves
- THEN the service is saved with that step's product, quantity and unit
