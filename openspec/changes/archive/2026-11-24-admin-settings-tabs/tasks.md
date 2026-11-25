# Tasks: admin-settings-tabs

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The admin page splits into tabs
  - Code: `src/views/settings/Settings.vue` (tab strip with `lazyTabs`, `ADMIN_TABS`, `?tab=`, page title and breadcrumb naming the open tab)
  - Tests: `tests/vitest/adminSettingsTabs.spec.js`
  - Note: The page has a seventh tab, Customer lookup at the till (`pos-lookup`), added after this change; the six tabs the spec names are all there.
