# call-review Specification (delta)

## Purpose

Recorded calls are transcribed and summarised on their contact moment, and
reviewed against a scorecard with coaching points the agent agrees. From
pipelinq matrix rows `cm-quality-review` and `cm-transcribe`.

## ADDED Requirements

### Requirement: A recorded call is transcribed and summarised (REQ-CRV-001)

When transcription is switched on, the system SHALL transcribe a contact moment's
recording through Nextcloud's speech-to-text task after the recording is
attached, SHALL store the transcript and a summary on the contact moment, and
SHALL NOT keep the audio. When transcription cannot run, the contact moment
SHALL say why.

#### Scenario: An agent finds the summary after a call

- GIVEN transcription is on and a speech-to-text provider is installed
- WHEN a recorded call ends and the platform attaches its recording to the contact moment
- THEN TicketDetail of that contact moment shows a transcript and a summary of a few sentences
- AND no audio file exists in Nextcloud Files or OpenRegister for that call

#### Scenario: No speech-to-text provider

- GIVEN transcription is on but no provider serves speech to text
- WHEN a recording is attached
- THEN the contact moment shows transcript status unavailable

### Requirement: A reviewer scores a call and writes coaching points (REQ-CRV-002)

A member of the call review group SHALL be able to open a recorded contact
moment, score it on the active scorecard's criteria, write coaching points and
share the review with the agent. The system SHALL compute a weighted total. An
agent SHALL see only reviews of their own calls.

#### Scenario: A team lead reviews a call

- GIVEN a team lead in the call review group and a recorded call by agent Sanne
- WHEN they open it from Call reviews, score greeting 4, answer 3 and closing 5, add two coaching points and press Share with agent
- THEN the review shows a weighted total and status shared

#### Scenario: Agents do not see each other's reviews

- GIVEN a shared review of Sanne's call
- WHEN agent Mehmet opens My Work
- THEN that review is not listed for him

### Requirement: The agent agrees the coaching points (REQ-CRV-003)

The agent SHALL see shared reviews of their calls in My Work and SHALL be able to
mark each coaching point agreed. When all points are agreed the review SHALL show
agreed to the reviewer.

#### Scenario: Sanne agrees both points

- GIVEN a shared review with two coaching points
- WHEN Sanne presses Agree on both in My Work
- THEN the reviewer sees the review with status agreed and the dates
