# Client forms (delta)

## ADDED Requirements

### Requirement: The client form is configuration shared by every create

The new-client dialog, the Clients page's edit form and any other create SHALL ask the fields and run the checks the Clients page declares, through the library form dialog.

#### Scenario: a wrong email address
- WHEN an agent types `jan@example` as a new client's email
- THEN the form reads "Invalid email format" and nothing is saved

#### Scenario: a picker's new client
- GIVEN the request form's client picker opened New client
- WHEN the client is saved
- THEN the picker gets the new client and the request form stays open
