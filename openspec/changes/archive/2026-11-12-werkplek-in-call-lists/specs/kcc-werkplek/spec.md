# kcc-werkplek Delta: werkplek-in-call-lists

## ADDED Requirements

### Requirement: The in-call lists are rows

While a call is on, the client's open matters, the tasks and callback requests and the earlier contact SHALL each be
drawn as rows with a title, a meta line and, for matters and tasks, a pill, without a table head. A handled matter
SHALL read "Klaar", an open one with an SLA deadline "Uiterlijk {date}". The earlier contact SHALL link to all the
client's contact moments. While a call is on the header SHALL NOT offer "Contact vastleggen", since the page carries
its own contact form.

#### Scenario: Board PqWerkplekKlant

- **WHEN** the agent takes a call from a known client with an open request due 11 October
- **THEN** "Openstaande zaken" shows the request as a row with "Uiterlijk 11 okt"
- **AND** the header shows "Beëindigen en afronden" but not "Contact vastleggen"
