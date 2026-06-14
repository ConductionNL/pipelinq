# Tasks: 08 Performance Dashboard

## PerformanceDashboard.vue (Task 3.4 of giant)

- [x] Create `src/views/blasts/PerformanceDashboard.vue`
- [x] Tab 1 Overview: table of recent blasts (name, segment, status, sent, delivered, open rate %, click rate %, unsubscribed) with sorting
- [x] Tab 2 A/B Testing: side-by-side variant comparison; once N>=500 each and 24h elapsed compute chi-square; display p-value + interpretation; show not-yet-available when N<500
- [x] Tab 3 Attribution: table of blasts with attributed deal count + attributed value (EUR); GET `/api/blasts/:id/attribution` to sum
- [x] nl + en i18n strings; CSS variables (no hardcoded colors); add `@spec` reference
