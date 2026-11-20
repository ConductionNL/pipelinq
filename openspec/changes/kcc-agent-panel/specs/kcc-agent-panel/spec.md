# kcc-agent-panel Specification (proposed)

**Spec refs**: integriq `kcc-cti-adapter` (`CallEvent`, REQ-005/006/007),
`events-cloudevents` REQ-001/REQ-002, nextcloud-vue `add-live-updates-plugin`,
ADR-031, ADR-032

## Purpose

A shared contact-centre surface: when a call is routed through integriq's
`kcc-cti-adapter`, pipelinq's agent panel shows who is calling and what is
open for them, and any app that wants that context links to it instead of
building its own. Split out of dossiq's `contacts-domain` change, task 4.2
(Tier B item B20), on Ruben's 2026-09-11 decision.

## ADDED Requirements

### Requirement: A call event is mirrored and resolved once

On every call event a telephony adapter delivers, pipelinq SHALL persist one
`mirroredCall` OpenRegister object keyed by `callId`, `kind` and `sourceId`,
idempotent on repeat delivery. The mirror SHALL NOT depend on a telephony
platform: integriq's `CallEvent` reaches it in process, through a listener
registered for that event class, and nothing fires when integriq is absent.
At write time pipelinq SHALL resolve, and store on the record: any pipelinq
`client`/`contact` whose phone number is exactly `CallEvent.callerNumber` in
E.164, together with that record's open `ticket` rows; and any OpenRegister
row of a schema implementing `https://openregister.app/ns#Requester` or
`.../ns#Customer` whose identifying-number field matches a number carried by
`CallEvent.caller`. The resolved party itself SHALL NOT be stored, only its
name. The caller's number SHALL be stored once, in `callerNumber`, and
nowhere else on the record. `mirroredCall` rows SHALL be dropped by a
scheduled job 30 days after they were written.

An event's fields come from a telephony platform's webhook and SHALL NOT be
trusted. An event whose `callId`, `sourceId` or `kind` is empty, longer than
the schema allows, not a known kind or carrying a control character SHALL be
refused, not cut. Every other field SHALL be brought inside the bounds of
the `mirroredCall` schema or left empty: a number that is not E.164, a
moment that is not ISO 8601 and an agent that is not a user id are stored
empty; of an open case only its reference and its source are kept, and at
most 20 of them. One event SHALL write at most one record.

#### Scenario: A ringing call is mirrored once

- GIVEN a `ringing` `CallEvent` for `callId = "42"`
- WHEN integriq dispatches the event
- THEN one `mirroredCall` record exists for `callId = "42"`, `kind = "ringing"`

#### Scenario: A repeated delivery does not duplicate the record

- GIVEN the `mirroredCall` record from the scenario above already exists
- WHEN the same event is dispatched again
- THEN no second `mirroredCall` record is created for that `callId`/`kind` pair

#### Scenario: A caller matching a pipelinq client resolves its open tickets

- GIVEN a pipelinq `client` with phone number `+31201234567` and one open
  `ticket`
- WHEN a `CallEvent` with `callerNumber = "+31201234567"` is mirrored
- THEN the stored `mirroredCall` record's "in pipelinq" matches include that
  client and that open ticket

#### Scenario: A caller matching a requester row elsewhere in the fleet

- GIVEN a `CallEvent.caller` carrying a BSN that matches a `brpPerson` row
  in another app's register
- WHEN the event is mirrored
- THEN the stored `mirroredCall` record's "elsewhere" matches include that row's
  source app and id

#### Scenario: Retention drops old calls

- GIVEN a `mirroredCall` record older than 30 days
- WHEN the retention job runs
- THEN the record no longer exists

#### Scenario: A hostile event stays inside the schema

- GIVEN a `CallEvent` whose caller name carries control characters, whose
  number is not E.164, and whose 200 open cases each carry a party beside
  the reference
- WHEN the event is mirrored
- THEN one `mirroredCall` record is written that the `mirroredCall` schema
  accepts, with an empty number, at most 20 open cases, and of each case
  only its reference and source

#### Scenario: A call id that cannot be a key is refused

- GIVEN a `CallEvent` whose `callId` is longer than 255 characters
- WHEN the event is mirrored
- THEN no `mirroredCall` record is written and nothing is read

@e2e exclude mirroring, idempotency and retention are backend-only; covered
by PHPUnit on `CallMirror`, `MirroredCallRow`, `CallerResolver`,
`CallEventListener` and `CallMirrorRetentionJob`.

### Requirement: The mirror writes as the background service account

A call event arrives with no user signed in: it starts at a telephony
platform's signed webhook. Pipelinq SHALL write, read back and remove
`mirroredCall` records as one Nextcloud account an admin picks, the
background service account, with OpenRegister's access checks on. The
`mirroredCall` schema SHALL grant that account's group,
`pipelinq-background-service`, create, read and delete, and SHALL grant
nobody else anything; no other pipelinq schema SHALL name the group, and
nobody may change a record. Without a usable account (none picked, unknown,
disabled, or outside the group) the mirror SHALL read nothing, write nothing
and remove nothing, and SHALL say so in the log; it SHALL NOT fall back to a
write or delete without access checks. The lookup of which clients, contacts
and open tickets have the caller's number is a read over the whole register
without access checks; its result goes into the record only and is returned
to no caller. An admin SHALL pick the account in the Pipelinq settings, and
the administration overview SHALL warn while there is none.

#### Scenario: A call is mirrored as the service account

- GIVEN an admin picked `call-bot` as the background service account
- WHEN a `ringing` `CallEvent` is mirrored
- THEN the record is saved with access checks on while `call-bot` is the
  acting user, and nobody is the acting user afterwards

#### Scenario: Without a service account nothing is mirrored

- GIVEN no background service account is picked
- WHEN a `ringing` `CallEvent` is mirrored and the retention job runs
- THEN no record is read, written or removed, and the log has a warning
  that names the reason and the group

#### Scenario: A refused write is not retried without access checks

- GIVEN OpenRegister refuses the service account's write
- WHEN a `CallEvent` is mirrored
- THEN no record is written and no call to OpenRegister runs without access
  checks

#### Scenario: Only an admin picks the account

- GIVEN a user who is not an admin
- WHEN they read or save the background service account
- THEN the answer is 403 and nothing changes

@e2e exclude the acting identity is backend-only; covered by PHPUnit on
`BackgroundServiceAccount`, `CallMirror`, `BackgroundServiceAccountController`,
`BackgroundServiceAccountCheck` and `CreateBackgroundServiceGroup`.

### Requirement: The incoming-call card also shows a mirrored call

The incoming-call card (cti-screenpop-adapter REQ-CTI-012) SHALL also show
the newest open `mirroredCall` of the signed-in agent, beside calls from
pipelinq's own telephony; of the two, the call that started last shows. A
mirrored call is open when it has a `ringing` event for that agent, no
`ended` or `transferred` event, and rang less than two hours ago. The card
SHALL show without a telephony platform configured in pipelinq when calls
are mirrored, and the screen pop switch SHALL still turn it off.

The agent SHALL get the call's number and its times and nothing else. The
read runs as the background service account with access checks on, and an
agent SHALL only ever get a call whose `agentId` is their own user id. The
clients, contacts and tickets the mirror stored for the number SHALL NOT be
handed to the agent: the mirror read them without access checks, so the card
looks the number up again as the agent. A mirrored call has no contact
moment in pipelinq, so opening a record from the card SHALL NOT write a
ticket.

#### Scenario: A mirrored call shows on the card

- GIVEN a `ringing` `mirroredCall` for agent `noor` from five seconds ago
- WHEN `noor`'s card asks for the incoming call
- THEN the answer carries the call's number and start time, marked as
  coming from the mirror, and no name, case, client or ticket

#### Scenario: A colleague's call is never handed over

- GIVEN a `ringing` `mirroredCall` for agent `henk`
- WHEN `noor`'s card asks for the incoming call
- THEN the answer carries no call

#### Scenario: An ended call is not incoming

- GIVEN a `ringing` and an `ended` `mirroredCall` of the same call
- WHEN the agent's card asks for the incoming call
- THEN the answer carries no call

#### Scenario: Opening a record from a mirrored call writes no ticket

- GIVEN the card shows a mirrored call with one match
- WHEN the agent opens the client
- THEN the client's page opens and no ticket is read or saved

@e2e exclude a mirrored call needs integriq and a telephony source on the
instance; covered by PHPUnit on `MirroredCallReader` and `IncomingCallFinder`
and by vitest on `IncomingCallCard`; the live pass is task 1c.

### Requirement: The panel shows the caller and their open items

`KccAgentPanel` SHALL render the active call's caller number, the caller's
display name when integriq resolved one (otherwise "Unknown caller"), the
"in pipelinq" matches with their open tickets linking to pipelinq's own
detail pages, the "elsewhere" matches linking to their source app's own
page, and `CallEvent.openCases[]` as reference and source. The panel SHALL
update without a manual refresh when a new `mirroredCall` record is written. An
unmatched caller SHALL render an empty items state, never a hidden or
errored section.

#### Scenario: A known pipelinq client's call shows their open tickets

- GIVEN a `mirroredCall` record whose "in pipelinq" match is a client with one
  open ticket
- WHEN the panel is open
- THEN the client's name and the open ticket appear, and the ticket links
  to that client's own detail page

#### Scenario: A caller known only to another app links there

- GIVEN a `mirroredCall` record whose "elsewhere" match is a `brpPerson` row
  owned by another app
- WHEN the panel is open
- THEN a link to that app's own detail page for the row is shown, and no
  copy of the row's data beyond its display name and identifying number is
  rendered in pipelinq

#### Scenario: An unknown caller shows an honest empty state

- GIVEN a `mirroredCall` record with no "in pipelinq" and no "elsewhere" matches
- WHEN the panel is open
- THEN "Unknown caller" is shown and the items area shows no items, not an
  error or a hidden section

#### Scenario: A new call updates the panel live

- GIVEN the panel is open with no active call
- WHEN a new `mirroredCall` record is written
- THEN the panel shows it without the agent reloading the page

#### Scenario: `callId` focuses one call

- GIVEN two active `mirroredCall` records
- WHEN the panel is opened with `?callId=<id>` naming one of them
- THEN only that call is focused, regardless of which is newer

@e2e include Seed a `mirroredCall` record with an "in pipelinq" match, open the
panel, assert the client name, the open ticket and its link; seed a second
`mirroredCall` with no matches and assert the unknown-caller empty state; open
with `?callId=` naming the first and assert it, not the second, is focused.

### Requirement: Another app links in with a plain URL

Any app SHALL be able to reach the panel with a plain hyperlink to
`generateUrl('/apps/pipelinq/kcc/agent-panel')`, optionally with
`?callId=<id>`. Pipelinq SHALL NOT require the consuming app to import a
shared component, render an iframe, or add a build-time dependency on
pipelinq to link to the panel.

#### Scenario: A deep link from another app opens the focused panel

- GIVEN another app's page renders a link to
  `generateUrl('/apps/pipelinq/kcc/agent-panel', { callId: '42' })`
- WHEN the link is followed
- THEN pipelinq opens with call `42` focused, and the origin app required
  no pipelinq component or iframe to produce the link

@e2e include Build the link the way a consuming app would, follow it,
assert the panel opens focused on the named call.
