# Activity timeline (delta)

## ADDED Requirements

### Requirement: A client's activity is the library timeline

The client page SHALL show its activity with the library timeline over GET /api/timeline: newest first, 20 at a time with Load more, narrowed by the chips Contact moments, Tasks, Email and Calendar, and an empty sentence that says what will appear.

#### Scenario: narrowing to tasks
- GIVEN a client with contact moments and tasks
- WHEN the agent picks the Tasks chip
- THEN the timeline reads the endpoint again with `types[]=task` from page 1
