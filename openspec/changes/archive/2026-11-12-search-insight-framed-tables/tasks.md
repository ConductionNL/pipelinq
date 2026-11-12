# Tasks: search-insight-framed-tables

### Task 1: Framed tables with sort and filter heads on the search insight page
- **spec_ref**: `openspec/changes/search-insight-framed-tables/specs/marketing/spec.md#requirement-the-search-insight-tables-are-framed-and-sort-and-filter-from-their-heads`
- **files**: `src/views/marketing/SearchQueries.vue`, `src/views/marketing/KeywordIntelligence.vue`, `src/views/marketing/CompetitorWatches.vue`
- [x] Implement: CnDataTable `framed` + `filterable` + `localHeaders` for the four tables, sortable columns, no white head override
- [x] Test: `tests/vitest/searchInsightFramedTables.spec.js` (and the updated searchInsightHub, boardCardAndTableHeads, wideTablesFit, competitorWatchesDutch)
