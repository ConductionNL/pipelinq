# activity-timeline Specification

## Problem
Provide a chronological activity feed per contact, organization, and pipeline item in Pipelinq. All interactions -- status changes, notes, emails, calls, document uploads, field changes, and linked case events -- appear in one unified timeline. This gives the complete "klantbeeld" (customer view) that relationship managers need to understand the full history of any entity at a glance.
Activity timelines are the single most requested CRM feature and are implemented in nearly all modern CRM platforms -- they answer "what happened with this contact?" without searching through multiple views. Common approaches include audit-log-style timelines, journal-style activity tracking, and chronological task linking.

## Proposed Solution
Implement activity-timeline Specification following the detailed specification. Key requirements include:
- Requirement: Every entity MUST have a timeline view
- Requirement: Timeline MUST capture all interaction types
- Requirement: Timeline MUST support manual entries
- Requirement: Timeline MUST be filterable and searchable
- Requirement: Timeline MUST integrate with linked cases

## Scope
This change covers all requirements defined in the activity-timeline specification.

## Success Criteria
- View contact timeline
- View organization timeline
- Status change recorded
- Note added
- Document uploaded
