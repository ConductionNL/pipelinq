# Tasks: email-calendar-sync

## 1. Data Model
- [x] 1.1 Add `emailLink` schema to `pipelinq_register.json`
- [x] 1.2 Add `calendarLink` schema to `pipelinq_register.json`
- [x] 1.3 Update register's schemas list

## 2. Backend Services
- [ ] 2.1 Create `lib/Service/EmailSyncService.php`
- [ ] 2.2 Create `lib/Service/CalendarSyncService.php`
- [ ] 2.3 Create `lib/BackgroundJob/EmailSyncJob.php`

## 3. Frontend
- [ ] 3.1 Create `src/views/sync/SyncSettings.vue`
- [x] 3.2 Create `src/components/EmailTimeline.vue`
- [x] 3.3 Add sync settings route to `src/router/index.js`

## 4. Verification
- [x] 4.1 Run `npm run build` and verify no errors
