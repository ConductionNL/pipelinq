---
kind: code
depends_on: []
---

# My work roadmap: the twelve unbuilt V1 and Enterprise requirements

## Why

Until 7 October `openspec/specs/my-work/spec.md` held two copies of the My work spec. The second copy carried twelve requirements that were never built: personal KPI tiles, quick actions, a recent activity feed, upcoming follow-ups, calendar meetings, a notification summary, saved filters, a customisable layout, the phone view, role-based content, auto-refresh and priority and pipeline filters. The fix-up in #2227 (commit `de95e6def`) collapsed the file to the first copy and those twelve requirements went with it. Their text was recovered from `de95e6def^:openspec/specs/my-work/spec.md` (lines 528 to 916).

A requirement that lives nowhere cannot be built or tracked. This change brings all twelve back as unbuilt work, in one open change, so a builder can pick them up one at a time.

## What changes

The recovered text is not pasted back as it was. Three things moved since it was written, and each requirement follows the current state:

- **Requests are tickets.** Since `unify-ticket-supertype` My work lists every ticket type under "Tickets" (REQ-DPG-021). The labels below say Tickets.
- **Follow-ups are tasks.** My work already lists the user's follow-up tasks (`crmTask`) under the Follow-ups filter, and "Log a visit" creates one (REQ-MOB-003). The old plan for a `followUpDate` field on the lead is dropped: the follow-up requirement is about how a follow-up row reads, not a second source.
- **The board decides.** Where `PqMijnWerk` or `PqMobiel` draws an element, the requirement follows the board: the KPI tiles are the board's "Your week so far" tiles, saved filters are the board's "Saved views" panel. Where no board draws an element, the requirement says so and keeps the recovered behaviour.

Added requirements, all in `my-work`:

| ID | Requirement | Tier |
|---|---|---|
| REQ-MW-090 | KPI summary tiles ("Your week so far") | V1 |
| REQ-MW-100 | Quick actions | V1 |
| REQ-MW-110 | Recent activity on my items | V1 |
| REQ-MW-120 | Upcoming follow-ups read at a glance | V1 |
| REQ-MW-130 | Upcoming meetings from the calendar | V1 |
| REQ-MW-140 | Notification summary | V1 |
| REQ-MW-150 | Saved views | Enterprise |
| REQ-MW-160 | Customisable layout | Enterprise |
| REQ-MW-170 | Phone view keeps headers and filters in reach | MVP |
| REQ-MW-180 | Role-based content (my items, team, organisation) | V1 |
| REQ-MW-190 | Auto-refresh and a refresh button | V1 |
| REQ-MW-200 | Priority and pipeline filters | V1 |

## Capability rows

New rows in `openspec/parity/capabilities.json`, all `specified`, linked to this change: `work-my-week-stats`, `work-quick-create`, `work-upcoming-meetings`, `work-layout`, `work-team-view`, `work-auto-refresh`, `work-filter-priority-pipeline`.

Requirements already covered by an existing row get no new row: the notification summary (`work-notification`), the activity feed (`work-activity-feed`), saved views (`work-saved-view`), the phone view (`plat-phone`) and the follow-up rows (`work-queue`, `work-task`).

## Impact

- `src/views/MyWork.vue` and new components under `src/components/myWork/`.
- `lib/Service/WorklistService.php` for team and organisation scopes and the week stats.
- No new OpenRegister schema. Saved views use OpenRegister's saved views through nextcloud-vue's `CnSavedViewsControl`; layout preferences use `/api/settings/user`.
