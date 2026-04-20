# Tasks: crm-workflow-automation

## 1. Schema Definition

- [x] 1.1 Add `automation` and `automationLog` schemas to `lib/Settings/pipelinq_register.json`.
- [x] 1.2 Register both schemas in the pipelinq register schemas array.

## 2. Backend Service

- [x] 2.1 Create `lib/Service/AutomationService.php` with CRUD, matching, execution, and logging methods.

## 3. Backend Controller and Routes

- [x] 3.1 Create `lib/Controller/AutomationController.php` with index, show, create, update, destroy, history actions.
- [x] 3.2 Add 6 automation routes to `appinfo/routes.php`.

## 4. Event Integration

- [x] 4.1 Modify `ObjectEventHandlerService.php` to fire matching automations on entity events.

## 5. Frontend Store

- [x] 5.1 Register `automation` and `automationLog` object types in `src/store/store.js`.

## 6. Frontend Views

- [x] 6.1 Create `src/views/automations/AutomationList.vue`.
- [x] 6.2 Create `src/views/automations/AutomationBuilder.vue`.
- [x] 6.3 Create `src/views/automations/AutomationHistory.vue`.

## 7. Navigation and Routing

- [x] 7.1 Add automation routes to `src/router/index.js`.
- [x] 7.2 Add Automations settings nav item to `src/navigation/MainMenu.vue`.

## 8. Tests

- [x] 8.1 Create `tests/Unit/Service/AutomationServiceTest.php`.
- [x] 8.2 Create `tests/Unit/Controller/AutomationControllerTest.php`.
