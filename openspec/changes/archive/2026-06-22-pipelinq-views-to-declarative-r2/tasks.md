# Tasks: Pipelinq views → declarative (round 2)

## 1. Services
- [x] 1.1 Rewrite the `Services` manifest page (`80-appointment-booking-admin.json`) as `type:"index"` with currency/duration formats, `view`/`create` navigate actions
- [x] 1.2 Delete `src/views/bookings/ServiceList.vue` + its `ServiceListView` registry import/entry
- [x] 1.3 Build + live-verify currency/duration render, New → create form, row → detail; commit + push

## 2. Projects
- [x] 2.1 Rewrite the `Projects` manifest page (`65-project-task-hierarchy.json`) as `type:"index"` (budget currency, billable + ledger badges, New navigate)
- [x] 2.2 Delete `src/views/projects/ProjectList.vue` + its `ProjectList` registry import/entry
- [x] 2.3 Build + live-verify; commit + push (record overdue-row-highlight drop)

## 3. Resources
- [x] 3.1 Rewrite the `Resources` manifest page (`80-appointment-booking-admin.json`) as `type:"index"` with New navigate `id:"new"`
- [x] 3.2 Delete `src/views/bookings/ResourceList.vue` + its `ResourceListView` registry import/entry
- [x] 3.3 Build + live-verify; commit + push

## 4. BillingCategories
- [x] 4.1 Rewrite the `BillingCategories` manifest page (`25-billing-categories.json`) as `type:"index"` with swatch, DBA badge, defaultSort type-then-name
- [x] 4.2 Delete `src/views/billingCategories/BillingCategoryList.vue` + its `BillingCategoryListView` registry import/entry
- [x] 4.3 Build + live-verify swatch + sort; commit + push

## 5. Analytics
- [x] 5.1 Rewrite the `Analytics` manifest page (`60-klantbeeld-360.json`) as `type:"dashboard"` with pageFilters period + 4 endpoint stat widgets
- [x] 5.2 Delete `src/views/analytics/AnalyticsDashboard.vue` + its `AnalyticsDashboard` registry import/entry
- [x] 5.3 Build + live-verify KPIs populate + period re-query; commit + push

## 6. Close-out
- [x] 6.1 `npm run lint` clean on changed files; vitest ≥ baseline
- [x] 6.2 `openspec validate pipelinq-views-to-declarative-r2 --strict`; archive
