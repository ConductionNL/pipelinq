# cti-screenpop-adapter Specification (delta)

## Purpose

The screen pop gets its caller: an incoming-call card that asks which call is on
the agent's line and opens the matched record. Decision 231.

## ADDED Requirements

### Requirement: The incoming-call card opens the screen pop (REQ-CTI-012)

While an inbound call is open on an agent's own line, pipelinq SHALL show that
agent an incoming-call card on every pipelinq page. The card SHALL look the
caller up with the screen pop by the number of the call. It SHALL open the
matched record, the chooser for several matches, or the new-contact form. On an
install without a telephony platform the card SHALL show nothing, ask once and
report no error.

#### Scenario: An install without telephony shows no card

- GIVEN no telephony platform is configured
- WHEN an agent opens pipelinq
- THEN no incoming-call card shows
- AND `GET /api/cti/incoming-call` answers `enabled: false` once and is not asked again
- AND no error shows

#### Scenario: One match is named on the card and opens on a click

- GIVEN a call from 06 12 34 56 78 rings on agent Noor's line through queue Vergunningen
- AND exactly one client, Sanne de Vries, has that number
- WHEN Noor has pipelinq open
- THEN the card shows Incoming call, the number, the queue, the line and the seconds since the call started
- AND it names Sanne de Vries
- WHEN Noor clicks Answer and open
- THEN the page of Sanne de Vries opens
- AND the call's contact moment carries Sanne de Vries as its client
- @e2e exclude needs a `ringing` webhook from a telephony platform, which the CI instance does not have; covered by `tests/vitest/incomingCallCard.spec.js` ("a call with one match") and `tests/Unit/Service/Cti/IncomingCallFinderTest.php`

#### Scenario: Several matches open the chooser

- GIVEN a call from a number that two contacts share
- WHEN the card shows
- THEN it says how many contacts were found on the number
- WHEN the agent clicks Choose contact
- THEN the chooser lists the matches, each with a Select button, and offers New contact
- WHEN the agent selects one
- THEN that record opens and the call's contact moment is linked to it
- @e2e exclude needs a `ringing` webhook from a telephony platform; covered by `tests/vitest/incomingCallCard.spec.js` ("a call with several matches")

#### Scenario: An unknown number offers a new contact

- GIVEN a call from a number no client or contact has
- WHEN the card shows
- THEN it says no client was found on this number and offers New contact
- WHEN the agent fills in a name and saves
- THEN the contact is stored with the caller's number, the call's contact moment is linked to it, and its page opens
- @e2e exclude needs a `ringing` webhook from a telephony platform; covered by `tests/vitest/incomingCallCard.spec.js` ("a call from an unknown number")

#### Scenario: Not this client

- GIVEN the card names one match
- WHEN the agent clicks Not this client
- THEN the card drops the match and offers New contact
- AND nothing is linked to the call's contact moment
- @e2e exclude needs a `ringing` webhook from a telephony platform; covered by `tests/vitest/incomingCallCard.spec.js`

#### Scenario: An agent only sees a call on their own line

- GIVEN a call rings on the line of agent Noor
- WHEN agent Daan has pipelinq open
- THEN Daan sees no card
- @e2e exclude a server rule with no screen of its own: the endpoint reads the uid from the session and the finder checks the assignee again on every row; covered by `IncomingCallControllerTest::testTheSignedInAgentIsAskedAbout` and `IncomingCallFinderTest::testEndedStaleAndForeignCallsAreNotIncoming`

#### Scenario: The card goes when the call ends or the agent closes it

- GIVEN the card shows for a call
- WHEN the call ends, or the agent clicks Close
- THEN the card is gone
- AND the next call shows a card again
- @e2e exclude needs a `ringing` and an `ended` webhook from a telephony platform; covered by `tests/vitest/incomingCallCard.spec.js`

## MODIFIED Requirements

### Requirement: Inbound Screen-Pop on Call Answer (REQ-CTI-001)

@e2e exclude every scenario here starts with an inbound webhook delivered by a telephony platform, and the CI instance provisions none: tests/e2e/ci-seed.sh installs only pipelinq + openregister. The agent-facing side is the incoming-call card of REQ-CTI-012, covered by tests/vitest/incomingCallCard.spec.js. The lookup and the E.164 normalisation are asserted by tests/Unit/Service/CtiContactMatcherTest.php and tests/Unit/Service/PhoneNormaliserTest.php.

The system MUST look the caller of an inbound call up by number and offer the
agent the matching record. The agent opens it from the incoming-call card
(REQ-CTI-012); the browser does not navigate by itself, because board
PqInkomendGesprek draws a card the agent acts on.

**Feature tier**: MVP

#### Scenario: Single contact match is offered on the incoming-call card

- GIVEN a call arrives on the agent's extension from phone number `+31612345678`
- AND exactly one contact exists in the database with this phone number (after E.164 normalisation)
- WHEN the telephony platform sends a `ringing` or `answered` webhook to pipelinq
- AND screen-pop is enabled in `cti_adapter_config.screen_pop_enabled = true`
- THEN the agent's card calls `POST /api/cti/screen-pop` with the caller's phone number
- AND the card names that contact with a button that opens their page
- AND the contactmoment is created and, once the agent opens the contact, linked to it (see REQ-CTI-003)

#### Scenario: Multiple contact matches show chooser modal

- GIVEN a call arrives with phone number `+31612345678`
- AND two or more contacts exist with this number (e.g., different family members, employees of a business)
- WHEN the agent clicks Choose contact on the card
- THEN the system displays a modal in the agent's browser with the top 3 matches (ordered by most-recent-interaction first)
- AND each match shows: Contact name, associated client name, type
- AND a "New contact" button at the bottom allows the agent to create a new contact instead

#### Scenario: No contact match shows new contact intake form

- GIVEN a call arrives with phone number `+31612345678`
- AND NO contacts exist with this number after normalisation
- WHEN the agent clicks New contact on the card
- THEN the system displays a new contact intake form pre-filled with the caller's E.164 normalised number
- AND on submission, a new contact is created and the agent is routed to its page
- AND the contactmoment is linked to the newly created contact

#### Scenario: Screen-pop delay allows agent to hear greeting

- GIVEN the org has configured `cti_adapter_config.screen_pop_delay_ms = 2000`
- WHEN a call arrives
- THEN the card shows 2000ms after the browser learned of the call
- AND the contactmoment is still created immediately (the delay affects only the card)

#### Scenario: Screen-pop with normalisation handles number formats

- GIVEN a call arrives with the number in national format: `0612345678`
- AND the org's `default_country_code = NL`
- WHEN the screen-pop lookup runs
- THEN the system normalises the number to E.164: `+31612345678`
- AND the lookup matches against normalised stored numbers
- AND the original raw number `0612345678` is preserved in `contactmoment.channelMetadata.raw_inbound_number` for forensics
