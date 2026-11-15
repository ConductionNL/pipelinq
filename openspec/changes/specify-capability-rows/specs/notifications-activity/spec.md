# notifications-activity Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `auto-cross-app`.

## ADDED Requirements

### Requirement: A change made to a tracked object by another app gets the same follow-up

When another app in the same Nextcloud changes an object that pipelinq tracks, the system SHALL handle the object event the same way as a change made in pipelinq, with the same activity and notifications.

#### Scenario: A lead reassigned from another app

- GIVEN a lead stored in OpenRegister
- WHEN another app changes the lead's assignee through OpenRegister
- THEN the new assignee receives the lead assigned notification
- AND the change appears in the pipelinq activity stream
