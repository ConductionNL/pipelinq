# Entity Notes V1 - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Note Types and Categorization

Notes MUST support categorization by type with distinct verb values.

#### Scenario: Create a call log note
- GIVEN a user had a phone conversation
- WHEN they select "Call log" type and enter duration, outcome, summary
- THEN the comment MUST be created with verb "call_log"
- AND the note MUST display with a phone icon and structured metadata

#### Scenario: Create an email log note
- GIVEN a user wants to log an email interaction
- WHEN they select "Email log" type and enter subject, summary
- THEN the comment MUST be created with verb "email_log"

### Requirement: @Mentions with Notifications

Notes MUST support @mentioning other users with automatic notification dispatch.

#### Scenario: Mention a colleague in a note
- GIVEN a user types "@petra" in a note
- WHEN the note is saved
- THEN user "petra" MUST receive a Nextcloud notification

### Requirement: File Attachments

Notes MUST support attaching files from Nextcloud Files.

#### Scenario: Attach a document to a note
- GIVEN a user creates a note
- WHEN they attach a file from Nextcloud Files
- THEN the attachment MUST be linked to the note and downloadable from the timeline
