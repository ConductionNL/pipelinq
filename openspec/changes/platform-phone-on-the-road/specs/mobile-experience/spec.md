# Delta for mobile-experience: phone layout, links and the visit note

## ADDED Requirements

### Requirement: Numbers, addresses and mail are links (REQ-MOB-001)

Phone numbers, email addresses and postal addresses on client, contact and lead pages MUST be links that open the phone dialler, mail app and map app, and MUST keep the text the user typed.

#### Scenario: Tap to call

- **GIVEN** the client "Bakkerij De Jong" has the phone number 06 12 34 56 78
- **WHEN** Pieter taps the number on his phone
- **THEN** the dialler opens with 0612345678 and the page still shows the number as typed

#### Scenario: Tap for directions

- **GIVEN** the client has a postal address
- **WHEN** Pieter taps the address
- **THEN** the phone's map app opens with the address

### Requirement: My work works as a phone list (REQ-MOB-002)

At phone width the My work page MUST show one column with today's calls and follow-ups first, and MUST NOT scroll horizontally.

#### Scenario: Today first

- **GIVEN** Sanne has two follow-ups due today and five later this week
- **WHEN** she opens My work on a 390 pixel wide screen
- **THEN** the two due today are at the top and the page has no horizontal scrollbar

### Requirement: A visit is logged in one small sheet (REQ-MOB-003)

"Log a visit" on a client or lead MUST open a sheet asking only for a note and an optional follow-up date, and MUST record an outbound contact moment with channel "visit" linked to that client or lead, plus a follow-up task when a date is given.

#### Scenario: Note after a visit

- **GIVEN** Pieter has just left Bakkerij De Jong
- **WHEN** he taps "Log a visit", types "wants a quote for two ovens" and sets a follow-up for Friday
- **THEN** the client's timeline shows a visit contact moment with that note and a follow-up task is due on Friday

