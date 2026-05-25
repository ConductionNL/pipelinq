# Tasks: crm-workflow-automation

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for existing automation, webhook, or event-dispatcher implementations that overlap with AutomationService — document findings (expected: no overlap; generic WebhookService does not support conditional trigger matching).

## 1. Schema Definition

- [ ] 1.1 Add `automation` schema to `lib/Settings/pipelinq_register.json` with all properties from design: `name`, `trigger`, `triggerConditions`, `actions`, `isActive`, `lastRun`, `runCount`, `webhookUrl`, `n8nWorkflowId`.
- [ ] 1.2 Add `automationLog` schema to `lib/Settings/pipelinq_register.json` with properties: `automation`, `triggeredAt`, `triggerEntity`, `actionsExecuted`, `status`, `error`.
- [ ] 1.3 Register both schemas in the pipelinq register's `schemas` array.
- [ ] 1.4 Add 3 seed `automation` objects and 3 seed `automationLog` objects (Dutch values, per design Seed Data section) to `pipelinq_register.json` using `@self` envelope with `slug` keys.

## 2. Backend Service

- [ ] 2.1 Create `lib/Service/AutomationService.php` with SPDX header and `@spec` PHPDoc tags.
  - [ ] 2.1a `listAutomations(array $params = []): array` — `ObjectService::findObjects($register, 'automation', $params)`
  - [ ] 2.1b `getAutomation(string $id): array` — `ObjectService::findObject($register, 'automation', $id)`
  - [ ] 2.1c `saveAutomation(array $data): array` — `ObjectService::saveObject($register, 'automation', $data)`
  - [ ] 2.1d `deleteAutomation(string $id): void` — `ObjectService::deleteObject($register, 'automation', $id)`
  - [ ] 2.1e `getMatchingAutomations(string $trigger, array $entity): array` — query by trigger, filter by `triggerConditions` (stage, pipeline, valueThreshold); exclude inactive
  - [ ] 2.1f `executeAutomation(array $automation, array $entity): void` — dispatch each action in `actions` in sequence; stop on first failure; call `logExecution`
  - [ ] 2.1g `logExecution(string $automationId, string $triggerEntityId, array $actionsResult, string $status, ?string $error): void` — `saveObject` to `automationLog`; increment `runCount`; update `lastRun`
  - [ ] 2.1h Action handlers: `assign_lead`, `move_stage`, `send_notification` (via NotificationService), `add_note`, `webhook` (via IClientService HTTP POST)

## 3. Backend Controller and Routes

- [ ] 3.1 Create `lib/Controller/AutomationController.php` with SPDX header and `@spec` PHPDoc tags.
  - [ ] 3.1a `index()` — `@NoAdminRequired`; return `AutomationService::listAutomations()`
  - [ ] 3.1b `show(string $id)` — `@NoAdminRequired`; return single automation
  - [ ] 3.1c `create()` — Admin check via `IGroupManager::isAdmin()`; delegate to `saveAutomation`
  - [ ] 3.1d `update(string $id)` — Admin check; merge id into data; delegate to `saveAutomation`
  - [ ] 3.1e `destroy(string $id)` — Admin check; delegate to `deleteAutomation`
  - [ ] 3.1f `history(string $id)` — `@NoAdminRequired`; query `automationLog` filtered by `automation=$id`
  - [ ] 3.1g All catch blocks: log exception, return `JSONResponse(['message' => 'Operation failed'], 500)` — never expose `$e->getMessage()`
- [ ] 3.2 Add 6 automation routes to `appinfo/routes.php` (specific routes before `{id}` wildcards per ADR-016):
  ```
  GET    /api/automations               → automation#index
  GET    /api/automations/{id}          → automation#show
  POST   /api/automations               → automation#create
  PUT    /api/automations/{id}          → automation#update
  DELETE /api/automations/{id}          → automation#destroy
  GET    /api/automations/{id}/history  → automation#history
  ```

## 4. Event Integration

- [ ] 4.1 Modify `lib/Service/ObjectEventHandlerService.php`:
  - [ ] 4.1a Inject `AutomationService` via constructor DI.
  - [ ] 4.1b In `handle(Event $event)`, after existing event processing, determine trigger type from event (`lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created`).
  - [ ] 4.1c Call `$this->automationService->getMatchingAutomations($trigger, $entityData)` and loop over results calling `executeAutomation`.
  - [ ] 4.1d Wrap in try/catch — automation failures MUST NOT throw exceptions that break the primary event handler.

## 5. Frontend Store

- [ ] 5.1 In `src/store/store.js`, register object types via `createObjectStore`:
  - `automation` (schema slug: `automation`)
  - `automation-log` (schema slug: `automationLog`)
  - Use kebab-case names; register each exactly once.

## 6. Frontend Views

- [ ] 6.1 Create `src/views/automations/AutomationList.vue` with SPDX header.
  - Uses `CnIndexPage` + `useListView('automation', { sidebarState, objectStore })`
  - Columns: Name, Trigger (translated label), Status (toggle badge), Last Run (formatted datetime), Run Count, Actions
  - Status toggle: calls `PUT /api/automations/{id}` with flipped `isActive`; wrap in `try/catch`
  - Edit button: `$router.push({ name: 'AutomationEdit', params: { id } })`
  - History button: `$router.push({ name: 'AutomationHistory', params: { id } })`
  - Delete button: `CnDeleteDialog` — NEVER `window.confirm()`
  - All user-visible strings via `this.t('pipelinq', 'text')`

- [ ] 6.2 Create `src/views/automations/AutomationBuilder.vue` with SPDX header.
  - Props: `automationId` from route (string or `'new'`)
  - Sections: General (name), Trigger (dropdown + conditional config), Actions (ordered list of action cards), Save controls
  - Trigger dropdown options: `lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created` (translated labels in Dutch)
  - Stage selector (shown when trigger is `lead_stage_changed`) — populated from pipeline store
  - Action types: `assign_lead`, `move_stage`, `send_notification`, `add_note`, `webhook`
  - Action ordering: drag-reorder cards; order preserved in `actions` array
  - Validation: name required, trigger required, ≥1 action required
  - Save: `POST /api/automations` (create) or `PUT /api/automations/{id}` (edit) via `axios` from `@nextcloud/axios`
  - EVERY `await` wrapped in `try/catch` with user-facing error via `NcDialog`

- [ ] 6.3 Create `src/views/automations/AutomationHistory.vue` with SPDX header.
  - Props: `automationId` from route
  - `CnIndexPage` querying `automationLog` filtered by `automation={automationId}`
  - Columns: Triggered At, Trigger Entity (UUID link), Status (badge), Actions Executed (count), Error
  - Row click: inline expand showing `actionsExecuted` detail array
  - Empty state: "Nog geen uitvoeringen"

## 7. Navigation and Routing

- [ ] 7.1 Add automation routes to `src/router/index.js`:
  ```
  /automatisering            → AutomationList (name: 'AutomationList')
  /automatisering/new        → AutomationBuilder (name: 'AutomationNew')
  /automatisering/:id        → AutomationBuilder (name: 'AutomationEdit')
  /automatisering/:id/history → AutomationHistory (name: 'AutomationHistory')
  ```
  All named routes, props via arrow function for `:id` param.

- [ ] 7.2 Add "Automatisering" nav item to `src/navigation/MainMenu.vue` settings footer section (gear/cog icon, `NcAppNavigationItem` with `:to="{ name: 'AutomationList' }"`).

## 8. Pre-commit Verification

- [ ] 8.1 SPDX headers present on all new files (PHP + Vue): `grep -rL 'SPDX-License-Identifier' lib/Service/AutomationService.php lib/Controller/AutomationController.php src/views/automations/`
- [ ] 8.2 ObjectService calls use 3 positional args: `grep -n 'findObject\|saveObject\|findObjects' lib/Service/AutomationService.php` — verify each has `($register, $schema, ...)` form
- [ ] 8.3 No `$e->getMessage()` in controller responses: `grep -n 'getMessage()' lib/Controller/AutomationController.php`
- [ ] 8.4 Store names are kebab-case: `grep -n 'registerObjectType' src/store/store.js` — verify `'automation'` and `'automation-log'`
- [ ] 8.5 No raw `fetch()` in Vue files: `grep -rn 'fetch(' src/views/automations/`
- [ ] 8.6 No direct `@nextcloud/vue` imports: `grep -rn "from '@nextcloud/vue'" src/views/automations/`
- [ ] 8.7 All template components imported and listed in `components: {}` for each Vue file
- [ ] 8.8 Run `npm run build` — zero errors

## 9. Verification

- [ ] 9.1 Create an automation via the builder UI and verify it appears in the list.
- [ ] 9.2 Create a lead and verify the matching `lead_created` automation fires (check automationLog).
- [ ] 9.3 Toggle automation active/inactive and verify the toggle persists.
- [ ] 9.4 View execution history and verify log entries display correctly.
- [ ] 9.5 Verify admin-only mutation enforcement: non-admin user cannot create/edit/delete automations.
