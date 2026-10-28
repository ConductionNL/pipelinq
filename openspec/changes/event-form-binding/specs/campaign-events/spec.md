## ADDED Requirements

### Requirement: The registration form card edits the form in portaliq

An event MAY name the portaliq form binding its sign-up form is published with. When it does, the registration form card SHALL offer "Formulier bewerken", opening that form binding in portaliq. When it does not, the card SHALL NOT show the button.

#### Scenario: an event with a published form
- GIVEN the event "Informatieavond warmtenet" names its portaliq form binding
- WHEN the organiser opens the event
- THEN the form card shows "Formulier bewerken", which opens that form binding in portaliq

#### Scenario: an event without a form binding
- GIVEN an event that names no form binding
- WHEN the organiser opens the event
- THEN the form card shows no "Formulier bewerken" button
