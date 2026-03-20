# Email & Calendar Sync - Design

## Approach
1. Create EmailLink and CalendarLink schemas in pipelinq_register.json
2. Build EmailSyncService integrating with Nextcloud Mail
3. Build CalendarSyncService integrating with Nextcloud Calendar/DAV
4. Create ITimedJob background job for periodic email matching
5. Add per-user sync settings UI

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add EmailLink, CalendarLink schemas
- `lib/Service/EmailSyncService.php` - New email sync service
- `lib/Service/CalendarSyncService.php` - New calendar sync service
- `lib/BackgroundJob/EmailSyncJob.php` - Periodic email matching job
- `src/views/settings/SyncSettings.vue` - Per-user sync configuration
- `src/components/EmailTimeline.vue` - Email display in entity timelines
