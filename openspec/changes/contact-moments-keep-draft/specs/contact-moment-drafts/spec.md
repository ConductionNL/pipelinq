# contact-moment-drafts Specification (delta)

## Purpose

A half typed manual contact moment survives a closed tab or an expired session,
and is visible only to its author. From pipelinq matrix row `cm-keep-draft`.

## ADDED Requirements

### Requirement: A manual contact moment is kept as a private draft (REQ-CMD2-001)

While an agent types in the contact moment quick log, the system SHALL keep the
form as a draft on the server, readable only by that agent, excluded from every
ticket list and report, and removed seven days after its last change.

The draft SHALL be the contact moment itself: a `ticket` of type `interaction`
in OpenRegister's status `draft` (decisions 180 and 216), not an object of a
schema of its own. Saving SHALL make that same object definitive through
OpenRegister's owner action, so the saved contact moment keeps the draft's id
and passes the whole ticket schema. pipelinq SHALL NOT carry a draft cleanup of
its own: the `ticket` schema sets `draftLifetimeDays` to 7 and OpenRegister's
cleanup removes the draft. The schema SHALL set `draftsListed` to false, so the
agent's own drafts stay out of every ticket list, count and facet unless the
agent asks for drafts.

#### Scenario: The tab closes halfway through

- GIVEN a KCC agent has typed a subject and two lines of notes in the quick log on ClientDetail
- WHEN the browser tab closes
- AND the agent opens the same client again
- THEN the quick log offers the unsaved contact moment with the time it was last changed
- AND Restore draft puts the subject and notes back in the form

#### Scenario: A colleague cannot see the draft

- GIVEN agent Sanne has a draft for client Jansen
- WHEN agent Mehmet opens the quick log on client Jansen
- THEN no draft is offered to him

#### Scenario: Saving removes the draft

- GIVEN an agent restored a draft
- WHEN they press Save
- THEN the draft becomes the contact moment, under the same id
- AND opening the quick log again offers no draft

#### Scenario: A draft is not in the ticket list

- GIVEN agent Sanne has a draft for client Jansen
- WHEN Sanne opens the tickets of client Jansen, the queue or a ticket report
- THEN the draft is not listed and not counted
- AND it is listed once it is saved

#### Scenario: A draft that is not complete is not saved
@e2e exclude unit; tests/vitest/contactMomentDraft.spec.js covers the refusal and the kept form, ContactMomentDraftTicketTest validates the saved payload against the real ticket schema

- GIVEN a draft that holds a value the ticket schema refuses
- WHEN the agent presses Save
- THEN the quick log shows what was found and keeps the form and the draft

#### Scenario: An abandoned draft is removed after seven days
@e2e exclude background job; OpenRegister's DraftExpiryJob removes it, ContactMomentDraftTicketTest asserts the schema sets the seven days

- GIVEN a draft nobody changed for seven days
- WHEN OpenRegister's draft cleanup runs
- THEN the draft is removed, and the quick log never offered it after the seventh day

### Requirement: An expired session does not lose the text (REQ-CMD2-002)

When a save fails because the session has ended, the quick log SHALL keep the
form's content on screen, SHALL tell the agent to log in again in a new tab, and
SHALL save on the next Save once the session is back.

#### Scenario: The session ends during a long call

- GIVEN an agent's session expired while they typed
- WHEN they press Save
- THEN the form still shows their text with the message that the session has ended
- AND after logging in again in another tab, pressing Save creates the contact moment
