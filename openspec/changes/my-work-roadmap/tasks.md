# Tasks: my-work-roadmap

Each block is one requirement and can ship on its own. Every block ends with a Vitest spec and a Playwright spec under `tests/e2e/spec-coverage/my-work-roadmap/` that cites the scenario names, plus new strings in `l10n/en.json` and `l10n/nl.json`.

## 1. KPI tiles (REQ-MW-090)

- [ ] 1.1 `lib/Service/WorklistService.php`: `getWeekStats(string $userId, string $scope = 'mine'): array` with answered (week and today), within-deadline percentage (user and team), average answer time, converted to a case.
- [ ] 1.2 Route `GET /api/my-work/week-stats` on a `MyWorkController` with `#[NoAdminRequired]`, scoped to the signed-in user; unit test in `tests/Unit/Service/WorklistServiceTest.php`.
- [ ] 1.3 `src/components/myWork/MyWeekTiles.vue`, four tiles with notes, placed as on `PqMijnWerk`; a dash, not 0, for an average with no data.

## 2. Quick actions (REQ-MW-100)

- [ ] 2.1 Header buttons "New ticket", "New lead", "New contact" in `src/views/MyWork.vue`, each opening the entity's existing create modal from `src/modals/` with the assignee preset.
- [ ] 2.2 Hide "New lead" when the user cannot create leads; refresh the list after a ticket or lead is saved.

## 3. Recent activity (REQ-MW-110)

- [ ] 3.1 `src/components/myWork/RecentActivity.vue`: the 10 latest audit-trail entries of the user's assigned objects through OpenRegister's audit trail API.
- [ ] 3.2 Collapsed state per user through `/api/settings/user`; empty text "No recent activity on your items".

## 4. Follow-up rows (REQ-MW-120)

- [ ] 4.1 In `MyWork.vue` (task rows): "planned HH:MM" for today, "Tomorrow" or "In N days" otherwise, "Follow-up today" marker. Put the date logic in `src/services/myWorkGroups.js` with Vitest cases.

## 5. Meetings (REQ-MW-130)

- [ ] 5.1 `lib/Service/MyWorkCalendarService.php`: events of the next 7 days from the user's calendars through `OCP\Calendar\IManager`, kept when the description or location links a Pipelinq client, contact or lead; empty when the Calendar app is absent.
- [ ] 5.2 Route `GET /api/my-work/meetings`; `src/components/myWork/UpcomingMeetings.vue`, hidden when empty.

## 6. Notification summary (REQ-MW-140)

- [ ] 6.1 Header badge with the unread Pipelinq notification count through the Nextcloud notifications OCS API; list with icon, summary, relative time, link.
- [ ] 6.2 Mark read on open; no badge at 0.

## 7. Saved views (REQ-MW-150)

- [ ] 7.1 `CnSavedViewsControl` from nextcloud-vue on My work, storing type, Show completed, priority, pipeline and scope; the "Saved views" panel with counts as on `PqMijnWerk`.

## 8. Layout (REQ-MW-160)

- [ ] 8.1 `src/components/myWork/LayoutPanel.vue`: hide and reorder sections, stored in `/api/settings/user` under `myWorkLayout`; "Reset to default"; the work list cannot be hidden.

## 9. Phone (REQ-MW-170)

- [ ] 9.1 Below 768 px: filter chips with counts as on `PqMobiel`, sticky group headers with counts, 44 px targets kept; quick actions folded into one bottom-right button once block 2 is built.

## 10. Scope (REQ-MW-180)

- [ ] 10.1 Admin setting for the team manager groups in Pipelinq settings.
- [ ] 10.2 `WorklistService::getMine()` gains a scope (`mine`, `team`, `organisation`); team members resolved from the manager's group through `IGroupManager`; OpenRegister RBAC unchanged.
- [ ] 10.3 Scope switch, member list with open counts, "Back to team".

## 11. Refresh (REQ-MW-190)

- [ ] 11.1 Refresh button and a 5-minute timer in `MyWork.vue`, cleared on destroy; keep the scroll position; stale warning after 10 minutes of failed fetches.

## 12. Filters (REQ-MW-200)

- [ ] 12.1 Priority multi-select and pipeline select next to the type buttons; "Filters (N)" and "Clear filters"; pipeline filter leaves tickets and follow-ups alone.

## 13. Close

- [ ] 13.1 Set `built.state` of the matching rows in `openspec/parity/capabilities.json` as each block lands.
- [ ] 13.2 Archive this change into `openspec/specs/my-work` once all blocks are built, or split the unbuilt rest into a new change.
