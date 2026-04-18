# Tasks: terugbel-taakbeheer

## 1. Data Model
- [x] 1.1 Add `taak` schema to `pipelinq_register.json`
- [x] 1.2 Update register's schemas list

## 2. Backend
- [x] 2.1 Create `lib/Service/TaskService.php` with deadline calculation and validation
- [x] 2.2 Create `lib/BackgroundJob/TaskEscalationJob.php` for deadline monitoring

## 3. Frontend Views
- [x] 3.1 Create `src/views/tasks/TaskList.vue`
- [x] 3.2 Create `src/views/tasks/TaskDetail.vue`
- [x] 3.3 Create `src/views/tasks/TaskForm.vue`

## 4. Navigation and Routing
- [x] 4.1 Add task routes to `src/router/index.js`
- [x] 4.2 Add Tasks entry to `src/navigation/MainMenu.vue`
- [x] 4.3 Extend MyWork.vue to include tasks

## 5. Verification
- [x] 5.1 Run `npm run build` and verify no errors
- [x] 5.2 Manual testing via browser
