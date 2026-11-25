# Tasks: my-work-roadmap

Each block is one requirement and can ship on its own. Every block ends with a Vitest spec and a Playwright spec under `tests/e2e/spec-coverage/my-work-roadmap/` that cites the scenario names, plus new strings in `l10n/en.json` and `l10n/nl.json`.

## 1. KPI tiles (REQ-MW-090)

- [x] 1.1 `lib/Service/WorklistService.php`: `getWeekStats(string $userId, string $scope = 'mine'): array` with answered (week and today), within-deadline percentage (user and team), average answer time, converted to a case.
- [x] 1.2 Route `GET /api/my-work/week-stats` on a `MyWorkController` with `#[NoAdminRequired]`, scoped to the signed-in user; unit test in `tests/Unit/Service/WorklistServiceTest.php`.
- [x] 1.3 `src/components/myWork/MyWeekTiles.vue`, four tiles with notes, placed as on `PqMijnWerk`; a dash, not 0, for an average with no data. Built as manifest configuration: the `my-week` stats-block reads the endpoint, no hand-built component.

## 2. Quick actions (REQ-MW-100)

- [x] 2.1 Header buttons "New ticket" and "New contact" (no "New lead", as on `PqMijnWerk`) in `src/views/MyWork.vue`, each opening the entity's existing create modal from `src/modals/` with the assignee preset.
  - Built as manifest config: My work is a dashboard page, so the buttons are its `headerActions` (`my-work-new-ticket`, `my-work-new-contact`), `open-modal` on the registered `RequestCreateDialog` (whose board layout presets the agent as handler) and `ContactCreateDialog` (a contact has no assignee). Vitest `tests/vitest/myWorkQuickActions.spec.js`
- [x] 2.2 Refresh the list after a ticket is saved.
  - `RequestCreateDialog` takes `stayAfterCreate` (My work sets it, so Open after creating starts unticked) and emits `cn:page:refresh` after a create that stays, which the record lists read again on. Vitest `tests/vitest/myWorkQuickActions.spec.js`; Playwright `tests/e2e/spec-coverage/my-work-quick-actions.spec.ts` is written, not yet run (needs a seeded instance)

## 3. Recent activity (REQ-MW-110)

- [x] 3.1 `src/components/myWork/RecentActivity.vue`: the 10 latest audit-trail entries of the user's assigned objects through OpenRegister's audit trail API.
  - Built as a manifest record list, not a Vue file: `my-activity` on the MyWork page (side column) reads `GET /api/my-work/recent-activity` (`MyWorkActivityController`), which `RecentActivityService` and `ActivityTrail` answer from OpenRegister's audit trail (`ObjectServiceInterface::getLogs()`) of the 20 most recently changed items the worklist reads as the user, newest 10 first, with kind, title, who, when and the changed fields. PHPUnit `RecentActivityServiceTest`, `MyWorkActivityControllerTest`; Vitest `tests/vitest/myWorkRecentActivity.spec.js`.
- [x] 3.2 Collapsed state per user through `/api/settings/user`; empty text "No recent activity on your items".
  - Built (25 Nov 2026): `my-activity` declares `collapsible: true`; the card draws a chevron ("Collapse Recent activity" / "Expand Recent activity") that folds it to its header, and the page's per-user layout (on through `layoutPanel`) stores the fold, so it is still folded after a reload; Back to default opens it again. The library side is ConductionNL/nextcloud-vue#2362 (`widget-collapsible-section`), which pipelinq now depends on. The empty text was already in place. Vitest `tests/vitest/myWorkRecentActivity.spec.js`. The live check (fold, reload, still folded) runs in the nightly.
  - Decision (delegated, 25 Nov 2026): as for 8.1, the fold is stored by the library's per-user layout store (the user's Nextcloud preferences under `pipelinq`), not in `/api/settings/user`: one user's arrangement of My work stays in one place, and it needs no pipelinq code.

## 4. Follow-up rows (REQ-MW-120)

- [x] 4.1 In `MyWork.vue` (task rows): "planned HH:MM" for today, "Tomorrow" or "In N days" otherwise, "Follow-up today" marker. Put the date logic in `src/services/myWorkGroups.js` with Vitest cases.
  - Built as manifest config, not a Vue file: My work is a manifest page. The `my-follow-ups` record list reads `meta.cases` (a deadline from `@today` to `@today+1d` adds "planned {deadline|time}") and its `due` config (`todayLabel` "Follow-up today", `upcomingLabel` "Tomorrow" / "In {count} days", the library's "{count} days overdue" in the danger tone). The date logic is the library's own, so no `myWorkGroups.js`. Vitest `tests/vitest/myWorkFollowUpRows.spec.js` reads the config through the library's due text, row condition and template.

## 5. Meetings (REQ-MW-130)

- [x] 5.1 `lib/Service/MyWorkCalendarService.php`: events of the next 7 days from the user's calendars through `OCP\Calendar\IManager`, kept when the description or location links a Pipelinq client, contact or lead; empty when the Calendar app is absent.
  - Built as `lib/Service/MyWork/MyWorkCalendarService.php` with `MeetingRecordLink` (the first `/apps/pipelinq/{clients|contacts|leads}/<uuid>` link in the description, then the location). Shared calendars are skipped; the linked record is read through OpenRegister and an unreadable one lists nothing. PHPUnit `tests/Unit/Service/MyWork/MyWorkCalendarServiceTest.php`
- [x] 5.2 Route `GET /api/my-work/meetings`; `src/components/myWork/UpcomingMeetings.vue`, hidden when empty.
  - Built as `MyWorkMeetingsController::index()` (a failed calendar read answers an empty list) and, as manifest configuration rather than a hand-built component, the `my-meetings` record-list on My work, hidden by `visibleWhen` when the route has no meetings. PHPUnit `MyWorkMeetingsControllerTest`, Vitest `tests/vitest/myWorkMeetings.spec.js`; Playwright `tests/e2e/spec-coverage/my-work-roadmap/upcoming-meetings.spec.ts` is written, not yet run (needs a seeded instance)

## 6. Notification summary (REQ-MW-140)

- [x] 6.1 Header badge with the unread Pipelinq notification count through the Nextcloud notifications OCS API; list with icon, summary, relative time, link.
  - Built (25 Nov 2026) as manifest configuration: My work sets `config.notificationsBadge: true`. The library's `CnNotificationsBadge` (ConductionNL/nextcloud-vue#2373, `dashboard-notifications-badge`, schema 2.203.0) reads the notifications OCS API, keeps the `pipelinq` entries (what `lib/Notification/Notifier.php` prepares), shows their count in the header and lists them newest first with icon, summary, relative time and link; it reads again on every page refresh, so `autoRefresh` keeps the count current. Vitest `tests/vitest/myWorkNotificationsBadge.spec.js`; the library's behaviour in `tests/components/CnNotificationsBadge.spec.js` there.
- [x] 6.2 Mark read on open; no badge at 0.
  - Built with 6.1: opening a notification dismisses it through the OCS API (the notifications app's read), lowers the count and opens its link; with nothing unread, or without the notifications app, no badge shows. The live check (assign two tickets, read 2, open one, read 1) runs in the nightly.

## 7. Saved views (REQ-MW-150)

- [ ] 7.1 `CnSavedViewsControl` from nextcloud-vue on My work, storing type, Show completed, priority, pipeline and scope; the "Saved views" panel with counts as on `PqMijnWerk`.

## 8. Layout (REQ-MW-160)

- [x] 8.1 `src/components/myWork/LayoutPanel.vue`: hide and reorder sections, stored in `/api/settings/user` under `myWorkLayout`; "Terug naar de standaardindeling"; the work list cannot be hidden.
  - Progress (25 Nov 2026): My work turns on the library's layout panel (`config.layoutPanel: true`, Arrange page, board PqMijnWerkIndelen) with `config.layoutPanelLocked` naming the work list (the three ticket lists, My leads, My follow-ups and the phone list), so those sections can be moved but not hidden; Back to default returns to the manifest layout. Vitest `tests/vitest/myWorkLayoutPanel.spec.js`. The lock is built in nextcloud-vue (ConductionNL/nextcloud-vue#2342, schema 2.201.0, waiting for its merge and release); until that release the installed library ignores `layoutPanelLocked`. Done (25 Nov 2026): #2342 is merged and released in nextcloud-vue 2.221.0, which pipelinq now depends on. The live check (hide Recent activity, reload, it stays hidden; the work list cannot be switched off) runs in the nightly.
  - Decision (delegated, 25 Nov 2026): the arrangement is stored by the library's per-user layout store (the user's Nextcloud preferences under `pipelinq`), not under `myWorkLayout` in `/api/settings/user`. It is per user, survives a reload and needs no pipelinq code, which is what the requirement asks for; a second store would split one user's layout over two places.

## 9. Phone (REQ-MW-170)

- [ ] 9.1 Below 768 px: filter chips with counts as on `PqMobiel`, sticky group headers with counts, 44 px targets kept; quick actions folded into one "Actions" button in the header, as on `PqMobiel`, once block 2 is built.

## 10. Scope (REQ-MW-180)

- [x] 10.1 Admin setting for the team manager groups in Pipelinq settings.
  - Built: app setting `my_work_manager_groups` (group ids, comma separated, empty by default) in the declared `myWork` section of `src/views/settings/adminSections.json`, mounted for administrators on the admin page. Vitest `tests/vitest/myWorkManagerGroups.spec.js` and `adminSections.spec.js`.
- [x] 10.2 `WorklistService::getMine()` gains a scope (`mine`, `team`, `organisation`); team members resolved from the manager's group through `IGroupManager`; OpenRegister RBAC unchanged.
  - Built: `lib/Service/Worklist/WorkScope.php` says which scopes a user has (`team` for a member of a configured manager group, its team being every member of those groups; `organisation` for a Nextcloud administrator) and whose work each lists. `getMine(userId, limit, scope, member)` asks OpenRegister once per listed user (no assignee filter for the organisation), so every read stays under the user's own permissions. `GET /api/worklist/mine?scope=&member=` answers 400 for a scope or member the user may not ask for. PHPUnit `tests/Unit/Service/Worklist/WorkScopeTest.php`, `WorklistServiceTest` (team, one member, organisation, refused), `WorklistControllerTest`. The page's `/api/worklist/items` and the week tiles keep the user's own work until the scope switch (10.3) lands.
- [x] 10.3 Scope switch, member list with open counts, "Back to team".
  - Built as manifest configuration: `my-scope` (Whose work: My items, Team, Organisation, as `GET /api/my-work/team` lists them for the user, hidden when the user has only their own work) and `my-team` (on Team, every member with their open items and Back to team) at the top of the side column. The ticket lists, the phone list, the download and the week tiles pass `scope` and `member` on: `WorklistService::items()` takes one view array (bucket, lens, sort, done, scope, member) and `getWeekStats()` counts the scope's tickets. PHPUnit `MyWorkTeamControllerTest`, `WorkScopeTest`, `WorklistServiceTest`, `WorklistControllerItemsTest`, `WorkBucketsTest`; Vitest `tests/vitest/myWorkScopeSwitch.spec.js`. Not yet: the lens chip counts and the My leads and My follow-ups lists still read the user's own work, and the live check of the switch needs a browser.

## 11. Refresh (REQ-MW-190)

- [x] 11.1 Refresh button and a 5-minute timer in `MyWork.vue`, cleared on destroy; keep the scroll position; stale warning after 10 minutes of failed fetches.
  - Built (25 Nov 2026) as manifest configuration, not a Vue file: My work sets `config.autoRefresh` (`intervalSeconds` 300, `staleAfterSeconds` 600, `button`). The library (ConductionNL/nextcloud-vue#2364, `dashboard-auto-refresh`, schema 2.202.0) fires `cn:page:refresh` every 5 minutes, which every list reads again on in place (no reload, so the scroll position stays); the timer is cleared when the page goes. The header's refresh button reads at once and spins until the lists answer; when the reads keep failing and the last good one is older than 10 minutes, "Data may be outdated. Last updated: <time>" shows with Retry. Vitest `tests/vitest/myWorkAutoRefresh.spec.js`; the library's own behaviour in `tests/components/CnDashboardPageAutoRefresh.spec.js` there. The live check (wait 5 minutes, a newly assigned ticket appears) runs in the nightly.

## 12. Filters (REQ-MW-200)

- [x] 12.1 Priority multi-select and pipeline select next to the type buttons; "Filters (N)" and "Clear filters"; pipeline filter leaves tickets and follow-ups alone.
  - Partly built (24 Nov 2026): `GET /api/worklist/items` takes `?priority=urgent,high` (an unknown priority answers 400) and `?pipeline=<id>`. `lib/Service/Worklist/WorkFilters.php` keeps the chosen priorities, counts a missing priority as normal, and narrows only leads to the pipeline, so tickets and follow-ups stay. My work's bar has a Priority select ("All priorities") and a Pipeline picker over the pipeline objects ("All pipelines"), and every list and the download pass both values on. Tests: PHPUnit `WorkFiltersTest`, `WorklistServiceTest::testItemsHonourThePriorityAndPipelineFilters`, `WorklistControllerItemsTest`; Vitest `tests/vitest/myWorkPriorityPipelineFilters.spec.js`. Completed (24 Nov 2026): the Priority select takes several priorities (`multiple: true`), the type, priority and pipeline controls are `filter: true`, and the bar's `clear` shows "Filters (N)" and "Clear filters" (nextcloud-vue 2.209.0, ConductionNL/nextcloud-vue#2313). Clearing returns to all types, all priorities and all pipelines; the sorting and Show completed stay. Vitest `tests/vitest/myWorkPriorityPipelineFilters.spec.js`; the library's own behaviour is tested in `tests/components/CnWorkspaceFilterMultiAndClear.spec.js` there.

## 13. Close

- [ ] 13.1 Set `built.state` of the matching rows in `openspec/parity/capabilities.json` as each block lands.
  - Done for the blocks built so far: `work-my-week-stats` (090), `work-quick-create` (100), `work-upcoming-meetings` (130) and `work-team-view` (180) now read built; on 25 Nov 2026 `work-layout` (160, with 110's fold), `work-auto-refresh` (190) and `work-filter-priority-pipeline` (200) too. Stays open while blocks 7 (saved views, row `work-saved-view`) and 9 (phone) are unbuilt.
- [ ] 13.2 Archive this change into `openspec/specs/my-work` once all blocks are built, or split the unbuilt rest into a new change.
