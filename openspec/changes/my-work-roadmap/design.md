# Design: my-work-roadmap

## Source

The twelve requirements come from the second copy in `de95e6def^:openspec/specs/my-work/spec.md` (lines 528 to 916), dropped when #2227 collapsed the file. Their old ids REQ-MW-090 to REQ-MW-200 are kept, so the old "Current Implementation Status" notes still point at the right thing.

## Boards

- `PqMijnWerk` (canvas 5NkFW28vZUUij43xzxHg5a): the type filter with counts (All, Tickets, Leads, Follow-ups), Show completed, a Sort control ("Deadline first"), grouped rows with type pill, priority and due text, the "Your week so far" tiles, a "Start here" card and a "Saved views" panel.
- `PqMobiel`, panel a: My work on a phone, filter chips with counts on top, rows grouped Overdue, Today, Due this week.

What follows the board:

| Requirement | Board element |
|---|---|
| REQ-MW-090 tiles | "Your week so far": Answered, Within the deadline, Average answer time, Converted to a case. This replaces the old tiles (Open leads, Open requests, Overdue, Pipeline value), which were a copy of the dashboard's. |
| REQ-MW-120 follow-ups | Follow-up rows in the list with "planned 14:00". The old separate Follow-ups section from a lead `followUpDate` is dropped. |
| REQ-MW-150 saved views | "Saved views" panel with counts per view. |
| REQ-MW-170 phone | Chips with counts on `PqMobiel`. The old dropdown filter bar is dropped. |
| REQ-MW-180 scope | The saved view "Permits, whole team" on `PqMijnWerk` carries a team scope. |

Not drawn on any board: quick actions (REQ-MW-100), recent activity (REQ-MW-110), meetings (REQ-MW-130), the notification badge (REQ-MW-140), the layout panel (REQ-MW-160), the refresh button (REQ-MW-190) and the priority and pipeline filters (REQ-MW-200). For those the recovered behaviour stands, placed in the header next to the existing filter buttons.

## Decisions

- **D1. Follow-ups stay tasks.** `MyWork.vue` already merges the user's `crmTask` items as follow-ups, and "Log a visit" makes a follow-up task. A second date on the lead would give two answers to "when do I call back". So REQ-MW-120 only changes how a follow-up row reads.
- **D2. Saved views are OpenRegister's.** Per ADR-022 Pipelinq consumes nextcloud-vue's `CnSavedViewsControl` over OpenRegister saved views (row `work-saved-view`, change `nextcloud-vue/saved-views-shared-by-role` for sharing). The old text's own store under a user settings API is dropped.
- **D3. Layout preferences go to `/api/settings/user`.** The route exists (`settings#getUserSettings`, `settings#updateUserSettings`). The old path `/apps/pipelinq/api/user/settings` never existed.
- **D4. Recent activity reads the audit trail.** OpenRegister keeps an audit trail per object; the section reads it for the user's assigned items. No Pipelinq activity table.
- **D5. Scopes widen the query, not the permissions.** Team and organisation scopes ask `WorklistService` for more assignees; OpenRegister RBAC still filters what the user may read.
- **D6. Week tiles are computed server side.** `WorklistService` gets a `getWeekStats(userId, scope)` method so the four figures do not need every closed ticket in the browser.

## Out of scope, flagged

The board differs from the main spec in three places this change does not touch: it groups by "Answer today / Waiting for me / Waiting for the customer" where the spec groups by due date, it has a "Start here" card and a "Download" action, and the filter buttons carry counts on desktop. These need a decision before anyone builds them.
