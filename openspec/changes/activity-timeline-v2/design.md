# Activity Timeline V2 - Design

## Approach
1. Build ActivityTimeline.vue component consuming Nextcloud Activity API
2. Add timeline tab/section to all entity detail views
3. Build manual entry forms (call log, meeting log)
4. Implement client-side filtering by activity type
5. Add organization-level aggregation from linked contacts

## Files Affected
- `src/components/ActivityTimeline.vue` - New unified timeline component
- `src/components/timeline/CallLogForm.vue` - Manual call logging
- `src/components/timeline/MeetingLogForm.vue` - Manual meeting logging
- `src/views/clients/ClientDetail.vue` - Add timeline tab
- `src/views/leads/LeadDetail.vue` - Add timeline tab
- `src/views/requests/RequestDetail.vue` - Add timeline tab
- `lib/Service/ActivityService.php` - Extend with timeline query methods
