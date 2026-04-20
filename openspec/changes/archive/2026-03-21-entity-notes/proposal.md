# Entity Notes Specification

## Problem
Add internal notes/comments to all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager, enabling team collaboration and context tracking. Notes serve as the primary collaboration mechanism for CRM users, supporting categorized note types, rich text, @mentions, attachments, and privacy controls to match the workflows of government KCC and commercial sales teams.
**Standards**: Nextcloud Comments API (`OCP\Comments\ICommentsManager`), Nextcloud Activity API, Nextcloud Notifications API, OpenRegister Object Interactions pattern
**Cross-references**: [OpenRegister object-interactions](../../../openregister/openspec/specs/object-interactions/spec.md), [activity-timeline](../activity-timeline/spec.md)
---

## Proposed Solution
Implement Entity Notes Specification following the detailed specification. Key requirements include:
- Requirement: Notes CRUD on All Entity Types [MVP]
- Requirement: Note Types and Categorization [V1]
- Requirement: Note Creation UI with Inline Editor [V1]
- Requirement: Rich Text Formatting [V1]
- Requirement: Note Timeline Display [MVP]

## Scope
This change covers all requirements defined in the entity-notes specification.

## Success Criteria
- Add a note to an entity
- View notes on an entity
- Delete own note
- Empty notes state
- Notes on all four entity types
