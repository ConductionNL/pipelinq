# Tasks: queue-as-default-view

- [x] 1.1 Default view `queue` on the Tickets page, `allowSavedViews` on, Take it on for unassigned rows
  - Verify: Vitest `tests/vitest/queueDefaultView.spec.js`
- [x] 1.2 Remove the Queue page, its menu entries and its tour step; point the dashboard links at the view
  - Verify: Vitest `tests/vitest/queueDefaultView.spec.js`, `tests/vitest/structureProfile.spec.js`
- [x] 1.3 Redirect `/queue` to `/tickets?savedView=default:queue` (`src/retiredRoutes.js`)
  - Verify: Vitest `tests/vitest/retiredRoutes.spec.js`
