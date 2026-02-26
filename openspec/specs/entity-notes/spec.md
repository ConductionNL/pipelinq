# Entity Notes Specification

## Purpose

Add internal notes/comments to all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager, enabling team collaboration and context tracking.

## Requirements

### Requirement: Notes CRUD [MVP]

Users MUST be able to create, view, and delete notes on any Pipelinq entity.

#### Scenario: Add a note to an entity
- GIVEN the user is viewing a client, contact, lead, or request detail page
- WHEN the user types a message in the notes input and submits
- THEN a new note MUST be created via ICommentsManager
- AND the note MUST appear in the notes list with the user's name and timestamp
- AND the notes input MUST be cleared

#### Scenario: View notes on an entity
- GIVEN an entity has one or more notes
- WHEN the user views the entity detail page
- THEN all notes MUST be displayed in reverse chronological order (newest first)
- AND each note MUST show: author name, timestamp, message text

#### Scenario: Delete own note
- GIVEN the user is viewing an entity with notes they authored
- WHEN the user clicks delete on their own note
- THEN the note MUST be removed from ICommentsManager
- AND the note MUST disappear from the list
- AND notes authored by other users MUST NOT show a delete button

#### Scenario: Empty notes state
- GIVEN an entity has no notes
- WHEN the user views the entity detail page
- THEN a "No notes yet" message MUST be displayed
- AND the notes input MUST still be available

### Requirement: Notes on All Entity Types [MVP]

The notes component MUST work identically on all four entity types.

#### Scenario: Notes on clients
- GIVEN a client detail view
- THEN a notes section MUST be present using object type `pipelinq_client`

#### Scenario: Notes on contacts
- GIVEN a contact detail view
- THEN a notes section MUST be present using object type `pipelinq_contact`

#### Scenario: Notes on leads
- GIVEN a lead detail view
- THEN a notes section MUST be present using object type `pipelinq_lead`

#### Scenario: Notes on requests
- GIVEN a request detail view
- THEN a notes section MUST be present using object type `pipelinq_request`

### Requirement: Comment Cleanup [MVP]

When a Pipelinq entity is deleted, its associated comments MUST be cleaned up.

#### Scenario: Delete entity removes its notes
- GIVEN an entity with notes exists
- WHEN the entity is deleted via the Pipelinq UI
- THEN all associated comments MUST be removed from ICommentsManager via `deleteCommentsAtObject()`
