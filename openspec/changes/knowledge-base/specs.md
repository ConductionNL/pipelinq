# Specs: knowledge-base

## Delta Spec

This change implements the knowledge base (kennisbank) feature for Pipelinq CRM agents. The spec builds on the existing `openspec/specs/kennisbank/spec.md` which defines the full requirements.

### Summary of Requirements (from main spec)

| Requirement | Tier | Status |
|-------------|------|--------|
| Article Management (CRUD, publish, archive, versioning) | V1 | Partially implemented |
| Rich Text Editing (Markdown editor with preview) | V1 | Partially implemented |
| Search and Discovery (full-text, autocomplete, recent) | V1 | Partially implemented |
| Categorization and Taxonomy (hierarchical categories) | V1 | Stub components |
| Zaaktype Linking | V1 | Not implemented (deferred) |
| Agent Feedback (rating, suggestions) | V1 | Partially implemented |
| Public vs Internal Articles | V1 | Implemented (controller) |
| Article Lifecycle Notifications | V1 | Implemented (background job) |
| Article Analytics | Enterprise | Out of scope |
| Kennisbank Navigation | V1 | Implemented (nav + routes) |

### Delta: What This Change Adds/Fixes

1. **Fix KennisbankHome.vue** — Remove duplicate script/style blocks (merge artifact), consolidate into single clean component using the Pinia store
2. **Implement ArticleListItem component** — Currently a stub; needs article card with title, summary, category badge, status/visibility badges, search highlight
3. **Implement CategoryTree component** — Currently a stub; needs recursive tree rendering with article counts and click-to-filter
4. **Implement CategoryTreeNode component** — Currently a stub; needs recursive node with expand/collapse
5. **Implement ArticleFeedback component** — Currently a stub; needs helpful/not-helpful buttons with suggestion form
6. **Implement FeedbackSummary component** — Currently a stub; needs feedback stats display for editors
7. **Fix ArticleDetail.vue** — Currently uses regex Markdown rendering; should use the store-based approach with markdown-it like KennisbankDetail.vue
8. **Fix ArticleEditor.vue** — Load/save methods are empty stubs; wire to Pinia store
9. **Fix CategoryManager.vue** — Fetch/save methods are empty stubs; wire to Pinia store
10. **Fix existing tests** — PublicKennisbankControllerTest and KennisbankReviewJobTest have duplicate method bodies (merge conflict artifacts)
11. **Add KennisbankServiceTest** — Missing test coverage for KennisbankService
12. **Add KennisbankControllerTest** — Missing test coverage for KennisbankController
