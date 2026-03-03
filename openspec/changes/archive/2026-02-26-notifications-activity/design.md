# Design: notifications-activity

## Architecture

### Event-Driven Approach

Pipelinq is a thin client — the frontend saves objects directly via OpenRegister's API (`/apps/openregister/api/objects/...`). This means Pipelinq has no PHP controllers for lead/request CRUD. Instead, we hook into **OpenRegister's event system**:

- `ObjectCreatedEvent` — dispatched when any OpenRegister object is created
- `ObjectUpdatedEvent` — dispatched when any OpenRegister object is updated (provides both old and new state)

Both events expose `ObjectEntity` which has `getSchema()`, `getRegister()`, and `getObject()` (returns data array with `title`, `assignee`, `stage`, etc.).

The listener checks if the object's schema ID matches one of Pipelinq's configured schemas (`lead_schema`, `request_schema`, etc. from `IAppConfig`) and triggers notifications/activities accordingly.

### Schema ID Matching

`SettingsService::getSettings()` returns config with keys: `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`. The listener builds a reverse map: `{schemaId: entityType}` to identify Pipelinq objects from OpenRegister events.

### Event Types

| Event | Trigger | Setting Category | Notification Target | Activity Target |
|-------|---------|-----------------|-------------------|-----------------|
| `lead_created` | ObjectCreatedEvent for lead schema | `pipelinq_assignment` | Assignee (if set) | Assignee |
| `lead_assigned` | ObjectUpdatedEvent where assignee changed | `pipelinq_assignment` | New assignee | New assignee |
| `lead_stage_changed` | ObjectUpdatedEvent where stage changed | `pipelinq_stage_status` | Assignee | Assignee |
| `request_created` | ObjectCreatedEvent for request schema | `pipelinq_assignment` | Assignee (if set) | Assignee |
| `request_status_changed` | ObjectUpdatedEvent where status changed | `pipelinq_stage_status` | Assignee | Assignee |
| `note_added` | NotesController::create() | `pipelinq_notes` | Entity assignee | Entity assignee |

### User Notification Preferences

Nextcloud's Activity app provides built-in user settings at **Settings > Activity**. Each `ISetting` class appears as a row with toggles for "Stream" and "Email". Users can independently enable/disable each category:

- **Lead & request assignments** (`pipelinq_assignment`) — lead/request created and assigned events
- **Pipeline stage & status changes** (`pipelinq_stage_status`) — stage and status change events
- **Notes & comments** (`pipelinq_notes`) — note added events

The `ActivityService` uses `IEvent->setType()` with the setting identifier so Nextcloud's activity system automatically respects user preferences. No custom UI needed — Nextcloud handles it all.

### Files

#### New Files

1. **`lib/Listener/ObjectEventListener.php`** — Listens to `ObjectCreatedEvent` and `ObjectUpdatedEvent`. Determines entity type via schema ID matching. Delegates to NotificationService and ActivityService.

2. **`lib/Service/NotificationService.php`** — Wraps `\OCP\Notification\IManager`. Methods: `notifyAssignment($entityType, $title, $assignee, $objectId)`, `notifyStageChange(...)`, `notifyNoteAdded(...)`. Skips self-notifications (author === target).

3. **`lib/Service/ActivityService.php`** — Wraps `\OCP\Activity\IManager`. Methods: `publishCreated($entityType, $title, $author, $objectId)`, `publishAssigned(...)`, `publishStageChanged(...)`, `publishStatusChanged(...)`, `publishNoteAdded(...)`.

4. **`lib/Notification/Notifier.php`** — Implements `\OCP\Notification\INotifier`. Handles `prepare()` to localize notification subjects. Subject keys: `lead_assigned`, `request_assigned`, `lead_stage_changed`, `request_status_changed`, `note_added`.

5. **`lib/Activity/Provider.php`** — Implements `\OCP\Activity\IProvider`. Handles `parse()` to format activity events with localized rich subjects.

6. **`lib/Activity/Setting/AssignmentSetting.php`** — Implements `\OCP\Activity\ISetting`. Identifier `pipelinq_assignment`, name "Lead & request assignments". Default enabled for stream, disabled for email.

7. **`lib/Activity/Setting/StageStatusSetting.php`** — Implements `\OCP\Activity\ISetting`. Identifier `pipelinq_stage_status`, name "Pipeline stage & status changes". Default enabled for stream, disabled for email.

8. **`lib/Activity/Setting/NoteSetting.php`** — Implements `\OCP\Activity\ISetting`. Identifier `pipelinq_notes`, name "Notes & comments". Default enabled for stream, disabled for email.

9. **`lib/Activity/Filter.php`** — Implements `\OCP\Activity\IFilter`. Provides a "Pipelinq" filter in the Activity app sidebar.

#### Modified Files

10. **`lib/AppInfo/Application.php`** — Register event listeners for `ObjectCreatedEvent` and `ObjectUpdatedEvent` in `register()`. No activity registration needed in `register()` since we use `info.xml` for activity provider/setting/filter.

11. **`appinfo/info.xml`** — Add `<activity>` section for Provider, 3 Settings, Filter. Add `<notification>` section for Notifier.

12. **`lib/Controller/NotesController.php`** — After creating a note, call `NotificationService->notifyNoteAdded()` and `ActivityService->publishNoteAdded()`. Requires resolving the entity's assignee from OpenRegister.

### Notification Flow

```
Frontend saveObject() → OpenRegister API → ObjectCreatedEvent/ObjectUpdatedEvent
    → ObjectEventListener
        → checks schema ID against Pipelinq config
        → extracts entity type, title, assignee, old/new values
        → NotificationService->notifyAssignment() (if assignee set/changed)
        → ActivityService->publishCreated/Assigned/StageChanged()
```

### Notifier.prepare() Logic

```php
public function prepare(INotification $notification, string $languageCode): INotification {
    if ($notification->getApp() !== 'pipelinq') throw new UnknownNotificationException();
    $l = $this->l10nFactory->get('pipelinq', $languageCode);

    switch ($notification->getSubject()) {
        case 'lead_assigned':
            $params = $notification->getSubjectParameters();
            $notification->setParsedSubject($l->t('Lead assigned: %s', [$params['title']]));
            break;
        // ... etc
    }

    $notification->setIcon($this->urlGenerator->imagePath('pipelinq', 'app-dark.svg'));
    return $notification;
}
```

### ActivityProvider.parse() Logic

Similar to Notifier — reads event type, localizes subject with rich parameters (entity title as bold link), sets icon.

### Self-Action Filtering

Both NotificationService and the listener check: if `$currentUser === $targetUser`, skip the notification. Activity events are always published regardless (for the team timeline).

## Files Changed

- `lib/Listener/ObjectEventListener.php` (new — OpenRegister event listener)
- `lib/Service/NotificationService.php` (new — notification sending)
- `lib/Service/ActivityService.php` (new — activity publishing)
- `lib/Notification/Notifier.php` (new — notification rendering)
- `lib/Activity/Provider.php` (new — activity rendering)
- `lib/Activity/Setting/AssignmentSetting.php` (new — "Lead & request assignments" preference)
- `lib/Activity/Setting/StageStatusSetting.php` (new — "Pipeline stage & status changes" preference)
- `lib/Activity/Setting/NoteSetting.php` (new — "Notes & comments" preference)
- `lib/Activity/Filter.php` (new — activity sidebar filter)
- `lib/AppInfo/Application.php` (modified — register event listeners)
- `appinfo/info.xml` (modified — register notifier, activity components, 3 settings)
- `lib/Controller/NotesController.php` (modified — trigger note notifications/activities)
