## ADDED Requirements

### Requirement: Request Linked Contactmomenten

The system MUST display all contactmomenten linked to a request on the request detail view, and allow logging new contactmomenten from the request context.

**Feature tier**: MVP

#### Scenario: Display linked contactmomenten on request detail

- GIVEN a request "Bouwvergunning aanvraag" with 3 linked contactmomenten
- WHEN a user views the request detail
- THEN the system MUST display a "Contactmomenten" section showing all 3 linked contactmomenten
- AND each contactmoment MUST show: subject, channel icon, agent, contactedAt (formatted)
- AND clicking a contactmoment MUST navigate to the contactmoment detail view

#### Scenario: No linked contactmomenten

- GIVEN a request "Nieuwe aanvraag" with no linked contactmomenten
- WHEN a user views the request detail
- THEN the "Contactmomenten" section MUST show an empty state message "Geen contactmomenten geregistreerd"
- AND a "Log contactmoment" button MUST be displayed

#### Scenario: Quick-log contactmoment from request

- GIVEN a user is viewing request "Bouwvergunning aanvraag" linked to client "Gemeente Utrecht"
- WHEN the user clicks "Log contactmoment"
- THEN the quick-log form MUST open with the request and client fields pre-filled
- AND after submission, the contactmomenten section MUST refresh to include the new record
