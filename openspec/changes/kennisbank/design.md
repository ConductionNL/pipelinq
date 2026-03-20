# Kennisbank - Design

## Approach
1. Add kennisartikel and kenniscategorie schemas to pipelinq_register.json
2. Build article management UI with Markdown editor
3. Build category hierarchy management
4. Integrate with OpenRegister search for full-text search
5. Add feedback system for article quality tracking

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add kennisartikel, kenniscategorie schemas
- `src/views/Kennisbank.vue` - New knowledge base main view
- `src/views/kennisbank/ArticleDetail.vue` - Article view/edit
- `src/views/kennisbank/ArticleList.vue` - Searchable article list
- `src/components/kennisbank/CategoryTree.vue` - Category hierarchy
- `src/router/index.js` - Add kennisbank routes
