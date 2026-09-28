# contact-moment-drafts Specification (delta)

## Purpose

A half typed manual contact moment survives a closed tab or an expired session,
and is visible only to its author. From pipelinq matrix row `cm-keep-draft`.

## ADDED Requirements

### Requirement: A manual contact moment is kept as a private draft (REQ-CMD2-001)

While an agent types in the contact moment quick log, the system SHALL keep the
form as a draft on the server, readable only by that agent, excluded from every
ticket list and report, and destroyed seven days after its last change or when
the contact moment is saved.

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
- THEN the contact moment is created
- AND opening the quick log again offers no draft

### Requirement: An expired session does not lose the text (REQ-CMD2-002)

When a save fails because the session has ended, the quick log SHALL keep the
form's content on screen, SHALL tell the agent to log in again in a new tab, and
SHALL save on the next Save once the session is back.

#### Scenario: The session ends during a long call

- GIVEN an agent's session expired while they typed
- WHEN they press Save
- THEN the form still shows their text with the message that the session has ended
- AND after logging in again in another tab, pressing Save creates the contact moment
