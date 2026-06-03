# Design: knowledge-base

## Architecture

The kennisbank follows Pipelinq's thin-client architecture:
- **Data layer**: OpenRegister stores `kennisartikel`, `kenniscategorie`, `kennisfeedback` objects
- **Backend**: PHP controllers for public API and feedback; background job for review reminders
- **Frontend**: Vue 2 SPA with Pinia store querying OpenRegister API directly

## Component Map

### Backend (PHP)
| File | Purpose | Status |
|------|---------|--------|
| `lib/Service/KennisbankService.php` | Public article filtering, feedback validation | Exists, functional |
| `lib/Controller/KennisbankController.php` | Authenticated endpoints (feedback) | Exists, functional |
| `lib/Controller/PublicKennisbankController.php` | Public API (unauthenticated) | Exists, functional |
| `lib/BackgroundJob/KennisbankReviewJob.php` | Review reminder notifications | Exists, functional |

### Frontend (Vue)
| File | Purpose | Status |
|------|---------|--------|
| `src/store/modules/kennisbank.js` | Pinia store with CRUD, search, feedback | Exists, functional |
| `src/views/kennisbank/KennisbankHome.vue` | Main listing view | Broken (duplicate blocks) |
| `src/views/kennisbank/KennisbankDetail.vue` | Article detail (store-based) | Exists, functional |
| `src/views/kennisbank/KennisbankEditor.vue` | Article editor (store-based) | Exists, functional |
| `src/views/kennisbank/ArticleDetail.vue` | Article detail (options API) | Stub methods, needs store wiring |
| `src/views/kennisbank/ArticleEditor.vue` | Article editor (options API) | Stub methods, needs store wiring |
| `src/views/kennisbank/CategoryManager.vue` | Category CRUD view | Stub methods, needs store wiring |
| `src/components/kennisbank/ArticleListItem.vue` | Article card in lists | Stub, needs implementation |
| `src/components/kennisbank/CategoryTree.vue` | Category sidebar tree | Stub, needs implementation |
| `src/components/kennisbank/CategoryTreeNode.vue` | Recursive tree node | Stub, needs implementation |
| `src/components/kennisbank/ArticleFeedback.vue` | Feedback buttons | Stub, needs implementation |
| `src/components/kennisbank/FeedbackSummary.vue` | Feedback stats | Stub, needs implementation |

### Tests
| File | Purpose | Status |
|------|---------|--------|
| `tests/Unit/Controller/PublicKennisbankControllerTest.php` | Public API tests | Broken (duplicate methods) |
| `tests/Unit/BackgroundJob/KennisbankReviewJobTest.php` | Review job tests | Broken (duplicate methods) |
| `tests/Unit/Service/KennisbankServiceTest.php` | Service tests | Missing |
| `tests/Unit/Controller/KennisbankControllerTest.php` | Controller tests | Missing |

## Design Decisions

1. **Consolidate view duplicates**: The router imports `ArticleDetail`, `ArticleEditor`, `CategoryManager` from `views/kennisbank/`. These are the "canonical" views. The `Kennisbank*.vue` variants were created separately with store integration. We consolidate by making the `Article*.vue` and `CategoryManager.vue` files use the Pinia store.

2. **KennisbankHome.vue cleanup**: Has two `<script>` and `<style>` blocks from a merge conflict. Keep the store-based version (second block) as it's more complete with autocomplete, search, recent articles, and CategoryTree integration.

3. **Markdown rendering**: Use `markdown-it` (already a dependency used in KennisbankDetail.vue and KennisbankEditor.vue).

4. **Component design**: All stub components in `src/components/kennisbank/` need proper implementation with props, events, and Nextcloud component library usage.

## Seed Data

The `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas already exist in `lib/Settings/pipelinq_register.json`. No new schema additions needed. Seed data for testing is provided via OpenRegister API calls.
