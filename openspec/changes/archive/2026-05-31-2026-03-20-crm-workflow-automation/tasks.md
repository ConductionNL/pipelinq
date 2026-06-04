# Tasks: crm-workflow-automation

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for existing automation, webhook, or event-dispatcher implementations that overlap with AutomationService — document findings (expected: no overlap; generic WebhookService does not support conditional trigger matching).

## 1. Schema Definition

- [x] 1.1 Add `automation` schema to `lib/Settings/pipelinq_register.json` with all properties from design: `name`, `trigger`, `triggerConditions`, `actions`, `isActive`, `lastRun`, `runCount`, `webhookUrl`, `n8nWorkflowId`.
- [x] 1.2 Add `automationLog` schema to `lib/Settings/pipelinq_register.json` with properties: `automation`, `triggeredAt`, `triggerEntity`, `actionsExecuted`, `status`, `error`.
- [x] 1.3 Register both schemas in the pipelinq register's `schemas` array.
- [x] 1.4 Add 3 seed `automation` objects and 3 seed `automationLog` objects (Dutch values, per design Seed Data section) to `pipelinq_register.json` using `@self` envelope with `slug` keys.

## 2. Backend Service

- [x] 2.1 Create `lib/Service/AutomationService.php` with SPDX header and `@spec` PHPDoc tags.
  - [x] 2.1a `listAutomations(array $params = []): array` — `ObjectService::findObjects($register, 'automation', $params)`
  - [x] 2.1b `getAutomation(string $id): array` — `ObjectService::findObject($register, 'automation', $id)`
  - [x] 2.1c `saveAutomation(array $data): array` — `ObjectService::saveObject($register, 'automation', $data)`
  - [x] 2.1d `deleteAutomation(string $id): void` — `ObjectService::deleteObject($register, 'automation', $id)`
  - [x] 2.1e `getMatchingAutomations(string $trigger, array $entity): array` — query by trigger, filter by `triggerConditions` (stage, pipeline, valueThreshold); exclude inactive
  - [x] 2.1f `executeAutomation(array $automation, array $entity): void` — dispatch each action in `actions` in sequence; stop on first failure; call `logExecution`
  - [x] 2.1g `logExecution(string $automationId, string $triggerEntityId, array $actionsResult, string $status, ?string $error): void` — `saveObject` to `automationLog`; increment `runCount`; update `lastRun`
  - [x] 2.1h Action handlers: `assign_lead`, `move_stage`, `send_notification` (via NotificationService), `add_note`, `webhook` (via IClientService HTTP POST)

## 3. Backend Controller and Routes

- [x] 3.1 Create `lib/Controller/AutomationController.php` with SPDX header and `@spec` PHPDoc tags.
  - [x] 3.1a `index()` — `@NoAdminRequired`; return `AutomationService::listAutomations()`
  - [x] 3.1b `show(string $id)` — `@NoAdminRequired`; return single automation
  - [x] 3.1c `create()` — Admin check via `IGroupManager::isAdmin()`; delegate to `saveAutomation`
  - [x] 3.1d `update(string $id)` — Admin check; merge id into data; delegate to `saveAutomation`
  - [x] 3.1e `destroy(string $id)` — Admin check; delegate to `deleteAutomation`
  - [x] 3.1f `history(string $id)` — `@NoAdminRequired`; query `automationLog` filtered by `automation=$id`
  - [x] 3.1g All catch blocks: log exception, return `JSONResponse(['message' => 'Operation failed'], 500)` — never expose `$e->getMessage()`
- [x] 3.2 Add 6 automation routes to `appinfo/routes.php` (specific routes before `{id}` wildcards per ADR-016):
  ```
  GET    /api/automations               → automation#index
  GET    /api/automations/{id}          → automation#show
  POST   /api/automations               → automation#create
  PUT    /api/automations/{id}          → automation#update
  DELETE /api/automations/{id}          → automation#destroy
  GET    /api/automations/{id}/history  → automation#history
  ```

## 4. Event Integration

- [x] 4.1 Modify `lib/Service/ObjectEventHandlerService.php`:
  - [x] 4.1a Inject `AutomationService` via constructor DI.
  - [x] 4.1b In `handle(Event $event)`, after existing event processing, determine trigger type from event (`lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created`).
  - [x] 4.1c Call `$this->automationService->getMatchingAutomations($trigger, $entityData)` and loop over results calling `executeAutomation`.
  - [x] 4.1d Wrap in try/catch — automation failures MUST NOT throw exceptions that break the primary event handler.

## 5. Frontend Store

- [x] 5.1 In `src/store/store.js`, register object types via `createObjectStore`:
  - `automation` (schema slug: `automation`)
  - `automation-log` (schema slug: `automationLog`)
  - Use kebab-case names; register each exactly once.

## 6. Frontend Views

- [x] 6.1 Create `src/views/automations/AutomationList.vue` with SPDX header.
  - Uses `CnIndexPage` + `useListView('automation', { sidebarState, objectStore })`
  - Columns: Name, Trigger (translated label), Status (toggle badge), Last Run (formatted datetime), Run Count, Actions
  - Status toggle: calls `PUT /api/automations/{id}` with flipped `isActive`; wrap in `try/catch`
  - Edit button: `$router.push({ name: 'AutomationEdit', params: { id } })`
  - History button: `$router.push({ name: 'AutomationHistory', params: { id } })`
  - Delete button: `CnDeleteDialog` — NEVER `window.confirm()`
  - All user-visible strings via `this.t('pipelinq', 'text')`

- [x] 6.2 Create `src/views/automations/AutomationBuilder.vue` with SPDX header.
  - Props: `automationId` from route (string or `'new'`)
  - Sections: General (name), Trigger (dropdown + conditional config), Actions (ordered list of action cards), Save controls
  - Trigger dropdown options: `lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created` (translated labels in Dutch)
  - Stage selector (shown when trigger is `lead_stage_changed`) — populated from pipeline store
  - Action types: `assign_lead`, `move_stage`, `send_notification`, `add_note`, `webhook`
  - Action ordering: drag-reorder cards; order preserved in `actions` array
  - Validation: name required, trigger required, ≥1 action required
  - Save: `POST /api/automations` (create) or `PUT /api/automations/{id}` (edit) via `axios` from `@nextcloud/axios`
  - EVERY `await` wrapped in `try/catch` with user-facing error via `NcDialog`

- [x] 6.3 Create `src/views/automations/AutomationHistory.vue` with SPDX header.
  - Props: `automationId` from route
  - `CnIndexPage` querying `automationLog` filtered by `automation={automationId}`
  - Columns: Triggered At, Trigger Entity (UUID link), Status (badge), Actions Executed (count), Error
  - Row click: inline expand showing `actionsExecuted` detail array
  - Empty state: "Nog geen uitvoeringen"

## 7. Navigation and Routing

- [x] 7.1 Add automation routes to `src/router/index.js`:
  ```
  /automatisering            → AutomationList (name: 'AutomationList')
  /automatisering/new        → AutomationBuilder (name: 'AutomationNew')
  /automatisering/:id        → AutomationBuilder (name: 'AutomationEdit')
  /automatisering/:id/history → AutomationHistory (name: 'AutomationHistory')
  ```
  All named routes, props via arrow function for `:id` param.

- [x] 7.2 Add "Automatisering" nav item to `src/navigation/MainMenu.vue` settings footer section (gear/cog icon, `NcAppNavigationItem` with `:to="{ name: 'AutomationList' }"`).

## 8. Pre-commit Verification

- [x] 8.1 SPDX headers present on all new files (PHP + Vue): `grep -rL 'SPDX-License-Identifier' lib/Service/AutomationService.php lib/Controller/AutomationController.php src/views/automations/`
- [x] 8.2 ObjectService calls use 3 positional args: `grep -n 'findObject\|saveObject\|findObjects' lib/Service/AutomationService.php` — verify each has `($register, $schema, ...)` form
- [x] 8.3 No `$e->getMessage()` in controller responses: `grep -n 'getMessage()' lib/Controller/AutomationController.php`
- [x] 8.4 Store names are kebab-case: `grep -n 'registerObjectType' src/store/store.js` — verify `'automation'` and `'automation-log'`
- [x] 8.5 No raw `fetch()` in Vue files: `grep -rn 'fetch(' src/views/automations/`
- [x] 8.6 No direct `@nextcloud/vue` imports: `grep -rn "from '@nextcloud/vue'" src/views/automations/`
- [x] 8.7 All template components imported and listed in `components: {}` for each Vue file
- [x] 8.8 Run `npm run build` — zero errors

## 9. Verification

- [x] 9.1 Create an automation via the builder UI and verify it appears in the list.
- [x] 9.2 Create a lead and verify the matching `lead_created` automation fires (check automationLog).
- [x] 9.3 Toggle automation active/inactive and verify the toggle persists.
- [x] 9.4 View execution history and verify log entries display correctly.
- [x] 9.5 Verify admin-only mutation enforcement: non-admin user cannot create/edit/delete automations.
