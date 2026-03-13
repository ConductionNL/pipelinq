# Notifications & Activity Stream Specification (Delta)

## Purpose

Deliver real-time notifications and a team-visible activity timeline for CRM events so users stay informed about leads, requests, and collaboration actions.

## ADDED Requirements

### Requirement: CRM Notifications [V1]

Users MUST receive Nextcloud notifications when CRM actions directly affect them.

#### Scenario: Lead assignment notification
- GIVEN a user assigns a lead to another user
- WHEN the lead is saved with a new assignee
- THEN the new assignee MUST receive a notification with subject "Lead assigned: {title}"
- AND the notification MUST link to the lead detail view
- AND if the previous assignee is different, no notification is sent to them

#### Scenario: Request assignment notification
- GIVEN a user assigns a request to another user
- WHEN the request is saved with a new assignee
- THEN the new assignee MUST receive a notification with subject "Request assigned: {title}"
- AND the notification MUST link to the request detail view

#### Scenario: Note added notification
- GIVEN a user adds a note to an entity (lead, request, client, contact)
- WHEN the note is created
- THEN the entity's assignee MUST receive a notification (if different from note author)
- AND the notification subject MUST be "New note on {entityType}: {title}"

#### Scenario: Stage change notification
- GIVEN a lead's pipeline stage changes
- WHEN the lead is saved with a new stage value
- THEN the lead's assignee MUST receive a notification (if different from the user making the change)
- AND the notification subject MUST include the new stage name

#### Scenario: Self-action does not notify
- GIVEN a user performs an action on their own item (self-assign, add own note, change own lead's stage)
- THEN NO notification MUST be sent to that user

#### Scenario: Notification links to correct view
- GIVEN a notification is received for any CRM event
- WHEN the user clicks the notification
- THEN they MUST be navigated to the relevant detail view in Pipelinq

### Requirement: CRM Activity Stream [V1]

All significant CRM actions MUST be published to the Nextcloud Activity stream.

#### Scenario: Lead lifecycle activities
- GIVEN a lead is created, assigned, or has its stage changed
- THEN an activity event MUST be published
- AND the event MUST appear in the Activity stream of the affected user
- AND the event MUST show the author, action, and lead title

#### Scenario: Request lifecycle activities
- GIVEN a request is created or has its status changed
- THEN an activity event MUST be published
- AND the event MUST appear in the Activity stream of the affected user

#### Scenario: Note activity
- GIVEN a note is added to any entity
- THEN an activity event MUST be published
- AND the activity MUST show who added the note and on which entity

#### Scenario: Activity filtering
- GIVEN a user views the Activity app
- WHEN they filter by "Pipelinq"
- THEN only CRM-related activities MUST be shown

#### Scenario: Activity user settings
- GIVEN a user opens Activity settings
- THEN they MUST be able to toggle Pipelinq activities on/off for the stream
- AND they MUST be able to toggle Pipelinq activities on/off for email notifications

### Requirement: Per-Category Notification Preferences [V1]

Users MUST be able to configure which types of CRM notifications they receive via Nextcloud's standard user settings.

#### Scenario: Granular activity settings
- GIVEN a user opens Settings > Activity
- THEN they MUST see separate toggles for each Pipelinq notification category:
  - "Lead & request assignments" (assignment notifications)
  - "Pipeline stage & status changes" (stage/status change notifications)
  - "Notes & comments" (note added notifications)
- AND each category MUST have independent stream and email toggles

#### Scenario: Disabled category suppresses notifications
- GIVEN a user has disabled "Lead & request assignments" in their Activity settings
- WHEN a lead is assigned to them
- THEN they MUST NOT receive a notification for the assignment
- BUT the activity event MUST still be published (visible if they re-enable)

#### Scenario: Default notification settings
- GIVEN a new user has not changed any Activity settings
- THEN all Pipelinq notification categories MUST be enabled for the activity stream by default
- AND all Pipelinq notification categories MUST be disabled for email by default

### Requirement: Notification Rendering [V1]

Notifications MUST be properly localized and formatted for display.

#### Scenario: Localized notification text
- GIVEN a user's language is set to Dutch (nl)
- WHEN they receive a Pipelinq notification
- THEN the notification text MUST be in Dutch

#### Scenario: Rich notification subject
- GIVEN a notification references a lead or request
- WHEN displayed in the notification center
- THEN the entity title MUST be shown as a rich parameter (bold/linked)

#### Scenario: Notification icon
- GIVEN any Pipelinq notification
- WHEN displayed in the notification center
- THEN the Pipelinq app icon MUST be shown
