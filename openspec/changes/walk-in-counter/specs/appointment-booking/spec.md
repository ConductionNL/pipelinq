# appointment-booking: delta for walk-in-counter

## ADDED Requirements

### Requirement: A walk-in ticket records its desk and when the customer left

A walk-in ticket MAY name the desk the customer is called to. The Left action MUST set the status to abandoned and record the moment in `leftAt`.

#### Scenario: a customer leaves the queue

- GIVEN Joke Smit is waiting in the walk-in queue
- WHEN the employee chooses Left on her row at 09:55
- THEN her ticket is abandoned with `leftAt` 09:55
