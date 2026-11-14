# marketing-campaigns Delta: campaign-events

## ADDED Requirements

### Requirement: A campaign holds events people register for

pipelinq SHALL keep a `campaignEvent` schema (an event of a campaign with its places) and an `eventRegistration`
schema (one sign-up with its status: confirmed, waiting list or cancelled). The campaign page SHALL list the
campaign's events, each opening its event page.

#### Scenario: The events of a campaign

- **GIVEN** the campaign Warmtenet Oost 2026 holds the event Informatieavond warmtenet Oost
- **WHEN** the marketer opens the campaign
- **THEN** its Overview tab lists the event with its date and venue, and a click opens the event page

### Requirement: The event page counts its registrations

The event page SHALL show the confirmed registrations against the places, the waiting list, the registrations from
the newsletter and the registrations that were new in pipelinq, each counted from the event's registrations. It SHALL
show the registrations in a tab, and the sign-up form, the location and the history beside the tabs.

#### Scenario: An event with a waiting list

- **GIVEN** an event with 80 places, 64 confirmed and 9 waiting registrations
- **WHEN** the marketer opens it
- **THEN** the page reads Registered 64 of 80 places and Waiting list 9
