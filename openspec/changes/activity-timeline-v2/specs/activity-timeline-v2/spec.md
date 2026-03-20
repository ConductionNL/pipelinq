# Activity Timeline V2 - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Per-entity unified timeline view

Every entity (client, contact, lead, request) MUST have a dedicated timeline tab showing all interactions.

#### Scenario: View contact timeline
- GIVEN contact "Jan de Vries" has 15 interactions
- WHEN a user opens Jan's detail page
- THEN the timeline tab MUST show all 15 interactions in reverse chronological order
- AND each entry MUST display: timestamp, actor, action type icon, and description

#### Scenario: View organization timeline (aggregated)
- GIVEN organization "Gemeente Utrecht" has 3 contacts with combined 40 timeline entries
- WHEN a user opens the organization detail page
- THEN the timeline MUST show all 40 entries aggregated from all linked contacts

### Requirement: Timeline manual entries

Users MUST be able to log calls and meetings manually.

#### Scenario: Log a phone call
- GIVEN a user clicks "Log gesprek" on a contact
- WHEN they fill in duration, summary, and outcome
- THEN a timeline entry MUST be created with type "call"

### Requirement: Timeline filtering and search

The timeline MUST support filtering by activity type and text search.

#### Scenario: Filter by activity type
- GIVEN a contact has 50 timeline entries of mixed types
- WHEN a user filters by type "note"
- THEN only note entries MUST be shown
