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

On every integriq `CallEvent` delivered via the CloudEvents fan-out
(`nl.conduction.integriq.call.<kind>`), pipelinq SHALL persist one `kccCall`
OpenRegister object keyed by `callId` and `kind`, idempotent on repeat
delivery. At write time pipelinq SHALL resolve, and store on the record:
any pipelinq `client`/`contact` whose phone number matches
`CallEvent.callerNumber`, together with that record's open `ticket` rows;
and any OpenRegister row of a schema implementing
`https://openregister.app/ns#Requester` or `.../ns#Customer` whose
identifying-number field matches a number carried by `CallEvent.caller`.
`kccCall` rows older than 30 days SHALL be dropped by a scheduled job.

#### Scenario: A ringing call is mirrored once

- GIVEN a `ringing` `CallEvent` for `callId = "42"`
- WHEN the CloudEvent is delivered to pipelinq
- THEN one `kccCall` record exists for `callId = "42"`, `kind = "ringing"`

#### Scenario: A repeated delivery does not duplicate the record

- GIVEN the `kccCall` record from the scenario above already exists
- WHEN the same CloudEvent is delivered again
- THEN no second `kccCall` record is created for that `callId`/`kind` pair

#### Scenario: A caller matching a pipelinq client resolves its open tickets

- GIVEN a pipelinq `client` with phone number `+31201234567` and one open
  `ticket`
- WHEN a `CallEvent` with `callerNumber = "+31201234567"` is mirrored
- THEN the stored `kccCall` record's "in pipelinq" matches include that
  client and that open ticket

#### Scenario: A caller matching a requester row elsewhere in the fleet

- GIVEN a `CallEvent.caller` carrying a BSN that matches a `brpPerson` row
  in another app's register
- WHEN the event is mirrored
- THEN the stored `kccCall` record's "elsewhere" matches include that row's
  source app and id

#### Scenario: Retention drops old calls

- GIVEN a `kccCall` record older than 30 days
- WHEN the retention job runs
- THEN the record no longer exists

@e2e exclude webhook mirroring, idempotency and retention are backend-only;
covered by PHPUnit on `KccCallController` and `KccCallResolverService`.

### Requirement: The panel shows the caller and their open items

`KccAgentPanel` SHALL render the active call's caller number, the caller's
display name when integriq resolved one (otherwise "Unknown caller"), the
"in pipelinq" matches with their open tickets linking to pipelinq's own
detail pages, the "elsewhere" matches linking to their source app's own
page, and `CallEvent.openCases[]` as reference and source. The panel SHALL
update without a manual refresh when a new `kccCall` record is written. An
unmatched caller SHALL render an empty items state, never a hidden or
errored section.

#### Scenario: A known pipelinq client's call shows their open tickets

- GIVEN a `kccCall` record whose "in pipelinq" match is a client with one
  open ticket
- WHEN the panel is open
- THEN the client's name and the open ticket appear, and the ticket links
  to that client's own detail page

#### Scenario: A caller known only to another app links there

- GIVEN a `kccCall` record whose "elsewhere" match is a `brpPerson` row
  owned by another app
- WHEN the panel is open
- THEN a link to that app's own detail page for the row is shown, and no
  copy of the row's data beyond its display name and identifying number is
  rendered in pipelinq

#### Scenario: An unknown caller shows an honest empty state

- GIVEN a `kccCall` record with no "in pipelinq" and no "elsewhere" matches
- WHEN the panel is open
- THEN "Unknown caller" is shown and the items area shows no items, not an
  error or a hidden section

#### Scenario: A new call updates the panel live

- GIVEN the panel is open with no active call
- WHEN a new `kccCall` record is written
- THEN the panel shows it without the agent reloading the page

#### Scenario: `callId` focuses one call

- GIVEN two active `kccCall` records
- WHEN the panel is opened with `?callId=<id>` naming one of them
- THEN only that call is focused, regardless of which is newer

@e2e include Seed a `kccCall` record with an "in pipelinq" match, open the
panel, assert the client name, the open ticket and its link; seed a second
`kccCall` with no matches and assert the unknown-caller empty state; open
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
