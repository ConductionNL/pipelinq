# Tasks: kennisbank

## 1. Data Model

- [x] 1.1 Add `kennisartikel` schema to `pipelinq_register.json` with all properties per design
- [x] 1.2 Add `kenniscategorie` schema to `pipelinq_register.json`
- [x] 1.3 Add `kennisfeedback` schema to `pipelinq_register.json`
- [x] 1.4 Update register's schemas list to include the 3 new schemas

## 2. Backend Service

- [x] 2.1 Create `lib/Service/KennisbankService.php` with public article query, feedback submission, and score recalculation
- [x] 2.2 Create `lib/Controller/KennisbankController.php` with public article endpoints and feedback endpoint

## 3. Routes

- [x] 3.1 Add kennisbank API routes to `appinfo/routes.php`

## 4. Frontend Views

- [x] 4.1 Create `src/views/kennisbank/KennisbankHome.vue` — search, category browse, popular articles
- [x] 4.2 Create `src/views/kennisbank/ArticleList.vue` — filterable article list with status badges
- [x] 4.3 Create `src/views/kennisbank/ArticleDetail.vue` — rendered Markdown, feedback buttons, metadata
- [x] 4.4 Create `src/views/kennisbank/ArticleEditor.vue` — create/edit with Markdown preview
- [x] 4.5 Create `src/views/kennisbank/CategoryManager.vue` — admin category CRUD

## 5. Navigation and Routing

- [x] 5.1 Add kennisbank routes to `src/router/index.js`
- [x] 5.2 Add Kennisbank entry to `src/navigation/MainMenu.vue`

## 6. Verification

- [x] 6.1 Run `npm run build` and verify no errors
- [x] 6.2 Manual testing via browser
