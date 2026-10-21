# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: The booking header declares its time-gated actions

The booking page SHALL offer Confirm deposit as the primary action of a booking whose deposit is pending, and Mark
completed as the primary action of a confirmed booking that has ended. Mark no-show SHALL be offered for a confirmed
booking that has ended, Reschedule and Cancel booking for an open booking that has not ended, and Send reminder for a
confirmed booking that starts more than an hour from now. Reschedule SHALL ask for the new start time and open the new
booking.

#### Scenario: Rescheduling opens the new booking

- GIVEN a confirmed booking tomorrow
- WHEN the administrator reschedules it to next week
- THEN the page opens the new booking the reschedule made
