# Tasks: knowledge-base

## Frontend Components

### [x] Task 1: Fix KennisbankHome.vue — remove duplicate blocks and consolidate
- Remove the duplicate `<script>` and `<style>` blocks (merge conflict artifact)
- Keep the store-based implementation (second script block)
- Ensure proper template with search, autocomplete, category sidebar, and article list
- **Acceptance**: Single clean component that renders without errors, uses Pinia store

### [x] Task 2: Implement ArticleListItem component
- Replace stub with article card showing: title, summary (truncated), category name, status badge, visibility badge
- Support `searchQuery` prop for text highlighting
- Emit `click` event for navigation
- Follow Nextcloud styling conventions
- **Acceptance**: Component renders article data with proper badges and click handling

### [x] Task 3: Implement CategoryTree and CategoryTreeNode components
- CategoryTree: accepts `categories` (tree), `articleCounts`, `selectedCategory` props; emits `select`
- CategoryTreeNode: recursive rendering with expand/collapse, shows article count, supports up to 3 levels
- **Acceptance**: Hierarchical categories render with counts and selection state

### [x] Task 4: Implement ArticleFeedback and FeedbackSummary components
- ArticleFeedback: helpful/not-helpful buttons, suggestion text form, uses kennisbank store
- FeedbackSummary: shows total ratings, satisfaction rate, recent suggestions (for editors)
- **Acceptance**: Feedback submission works, summary shows aggregated data

## Frontend Views

### [x] Task 5: Wire ArticleDetail.vue to Pinia store
- Replace stub `fetchArticle()` with store call
- Use markdown-it for rendering (not regex)
- Wire feedback submission to store
- Add suggestion form functionality
- **Acceptance**: Article loads from store, Markdown renders properly, feedback works

### [x] Task 6: Wire ArticleEditor.vue to Pinia store
- Wire `loadArticle()` to store.fetchArticle
- Wire `save()` to store.createArticle/updateArticle
- Add category selector dropdown
- Use markdown-it for preview (not regex)
- Add duplicate title warning via store.checkDuplicateTitle
- **Acceptance**: Create/edit articles via store, preview renders properly

### [x] Task 7: Wire CategoryManager.vue to Pinia store
- Wire `fetchCategories()` to store
- Wire `saveCategory()` to store.createCategory/updateCategory
- Wire `deleteCategory()` to store.deleteCategory
- Add parent category selector for hierarchy
- **Acceptance**: Full CRUD for categories via store

## Backend Tests

### [x] Task 8: Fix PublicKennisbankControllerTest — remove duplicate methods
- Remove all duplicate method definitions (merge conflict artifacts)
- Keep one clean version of each test
- Ensure all tests pass
- **Acceptance**: `phpunit --filter PublicKennisbankControllerTest` passes with 0 errors

### [x] Task 9: Fix KennisbankReviewJobTest — remove duplicate methods
- Remove all duplicate method definitions (merge conflict artifacts)
- Keep one clean version of each test
- Ensure all tests pass
- **Acceptance**: `phpunit --filter KennisbankReviewJobTest` passes with 0 errors

### [x] Task 10: Add KennisbankServiceTest
- Test `getPublicArticles()` with various filter combinations
- Test `stripInternalFields()` removes correct fields
- Test `validateFeedback()` with valid/invalid data
- Test `buildFeedbackData()` builds correct object
- Test `calculateUsefulnessScore()` with edge cases (0 ratings, all positive, all negative, mixed)
- **Acceptance**: 5+ test methods, all pass

### [x] Task 11: Add KennisbankControllerTest
- Test `publicIndex()` returns query params
- Test `publicShow()` with valid and empty ID
- Test `submitFeedback()` with valid and invalid data
- **Acceptance**: 3+ test methods, all pass

## Quality

### [x] Task 12: Run quality checks and fix issues
- Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)
- Run `npm run lint`, `npm run stylelint`, `npm run build`
- Fix any violations
- **Acceptance**: All quality checks pass
