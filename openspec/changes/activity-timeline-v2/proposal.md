# Activity Timeline V2

## Problem
The app has Nextcloud Activity integration for publishing CRM events (creation, assignment, stage/status changes, notes), but lacks a dedicated per-entity unified timeline view. Users cannot see a chronological feed of all interactions on a single contact, organization, or pipeline item. No filtering, search, or manual entry capabilities exist.

## Current State (Implemented)
- `ActivityService.php` publishes events to Nextcloud activity stream
- Events cover: creation, assignment, stage changes, status changes, note additions
- No dedicated timeline UI component per entity

## Proposed Solution
Build a dedicated `ActivityTimeline.vue` component that aggregates all interactions per entity into a chronological feed. Add filtering by activity type, search, manual entry for calls/meetings, and cross-entity aggregation for organizations.

## Impact
- New ActivityTimeline.vue reusable component
- Integration into all entity detail views (client, contact, lead, request)
- Manual entry forms for calls and meetings
- Filter and search functionality
- Organization-level aggregation from linked contacts
