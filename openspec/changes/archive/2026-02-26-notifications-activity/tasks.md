# Tasks: notifications-activity

## 1. Notification Infrastructure

- [x] 1.1 Create `lib/Service/NotificationService.php` — constructor takes `IManager` (Notification), `IUserSession`, `LoggerInterface`. Methods: `notifyAssignment($entityType, $title, $assigneeUserId, $objectId, $author)`, `notifyStageChange($title, $newStage, $assigneeUserId, $objectId, $author)`, `notifyStatusChange($title, $newStatus, $assigneeUserId, $objectId, $author)`, `notifyNoteAdded($entityType, $entityTitle, $assigneeUserId, $objectId, $author)`. All methods skip if author === assignee.
- [x] 1.2 Create `lib/Notification/Notifier.php` — implements `INotifier`. Handle subjects: `lead_assigned`, `request_assigned`, `lead_stage_changed`, `request_status_changed`, `note_added`. Use `IFactory` for l10n. Set app icon via `IURLGenerator`.

## 2. Activity Infrastructure

- [x] 2.1 Create `lib/Service/ActivityService.php` — constructor takes `IManager` (Activity), `IUserSession`, `LoggerInterface`. Methods: `publishCreated($entityType, $title, $objectId)`, `publishAssigned($entityType, $title, $newAssignee, $objectId)`, `publishStageChanged($title, $newStage, $objectId)`, `publishStatusChanged($title, $newStatus, $objectId)`, `publishNoteAdded($entityType, $entityTitle, $objectId)`. All use current user as author.
- [x] 2.2 Create `lib/Activity/Provider.php` — implements `IProvider`. Parse event types: `lead_created`, `lead_assigned`, `lead_stage_changed`, `request_created`, `request_status_changed`, `note_added`. Build localized rich subjects with entity title as bold text.
- [x] 2.3 Create `lib/Activity/Setting/AssignmentSetting.php` — implements `ISetting`. Identifier `pipelinq_assignment`, name "Lead & request assignments". Covers `lead_created`, `lead_assigned`, `request_created` events. Default enabled for stream, disabled for email.
- [x] 2.4 Create `lib/Activity/Setting/StageStatusSetting.php` — implements `ISetting`. Identifier `pipelinq_stage_status`, name "Pipeline stage & status changes". Covers `lead_stage_changed`, `request_status_changed`. Default enabled for stream, disabled for email.
- [x] 2.5 Create `lib/Activity/Setting/NoteSetting.php` — implements `ISetting`. Identifier `pipelinq_notes`, name "Notes & comments". Covers `note_added`. Default enabled for stream, disabled for email.
- [x] 2.6 Create `lib/Activity/Filter.php` — implements `IFilter`. Identifier `pipelinq`, name "Pipelinq", filters types to all pipelinq event types, allows app `pipelinq`.

## 3. Event Listener

- [x] 3.1 Create `lib/Listener/ObjectEventListener.php` — listens to `ObjectCreatedEvent` and `ObjectUpdatedEvent`. Uses `SettingsService` to match schema IDs. On create: calls `ActivityService->publishCreated()` and `NotificationService->notifyAssignment()` (if assignee set). On update: detects assignee change → `notifyAssignment()` + `publishAssigned()`, stage change → `notifyStageChange()` + `publishStageChanged()`, status change → `notifyStatusChange()` + `publishStatusChanged()`.

## 4. Registration

- [x] 4.1 Update `lib/AppInfo/Application.php` — in `register()`: add event listeners for `ObjectCreatedEvent` and `ObjectUpdatedEvent` → `ObjectEventListener::class`.
- [x] 4.2 Update `appinfo/info.xml` — add `<activity>` section with Provider, 3 Setting classes (AssignmentSetting, StageStatusSetting, NoteSetting), and Filter. Add `<notification>` section with Notifier class.

## 5. Note Integration

- [x] 5.1 Update `lib/Controller/NotesController.php` — inject `NotificationService` and `ActivityService`. In `create()` action, after note is created: call `notifyNoteAdded()` and `publishNoteAdded()`. Resolve entity assignee by fetching the object from OpenRegister.

## 6. Build and Verify

- [x] 6.1 Run `npm run build` and verify no errors.
- [ ] 6.2 Test notifications and activity via browser.

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
