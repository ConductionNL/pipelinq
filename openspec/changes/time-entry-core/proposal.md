# Proposal: time-entry-core (timer, manual, weekly grid)

## Problem

Pipelinq has no time tracking functionality. 22 out of 26 analysed competitors offer time entry in at least one mode (timer, manual, or weekly grid). Without time entry, professional services organisations and knowledge workers using Pipelinq cannot log billable hours against clients and leads, making downstream invoicing and project profitability analysis impossible.

Specific gaps:
1. No start/stop timer for active work sessions
2. No manual entry for past hours
3. No weekly timesheet grid for multi-day overview
4. No per-entry comment or edit window

## Solution

Implement the Time Entry Core module — a foundational time tracking subsystem within Pipelinq that adds:

1. **Start/stop timer** — One-click timer visible from any page via a persistent header widget; automatically records start and end timestamps and computes duration
2. **Manual entry** — Form-based entry dialog for logging past hours with date, duration, description, and optional client/lead link
3. **Weekly timesheet grid** — Spreadsheet-style view showing all entries across a selected week, grouped by day; inline duration editing; weekly totals per row
4. **Edit window** — Full edit dialog for any existing entry (change date, duration, description, comment, billable flag, client/lead link)
5. **Comment per entry** — Free-text comment field separate from description, displayed in list and grid views

### Out of scope

- Idle detection and Pomodoro timer (Enterprise)
- Desktop auto-tracker (background window tracking) (Enterprise)
- Kiosk / shift-clock PIN mode (Enterprise)
- 6-minute billing increment rounding (V2)
- Bulk paste / mass import of entries (V2)
- Mobile native app (V2)
- Labour-law break compliance rules (V2)
- Entry approval workflow with manager review (V2)

## Impact

- **New schemas**: 1 (`timeEntry`)
- **New backend files**: 2 (`TimerController.php`, `TimeEntryService.php`)
- **New frontend files**: 5 views (`TimerWidget.vue`, `TimeEntryList.vue`, `ManualEntryDialog.vue`, `WeeklyGrid.vue`, `TimeEntryDetail.vue`)
- **Modified files**: 3 (`pipelinq_register.json`, `appinfo/routes.php`, `src/router/index.js`, `src/navigation/MainMenu.vue`)
- **Risk**: Medium — introduces first time-tracking subsystem; timer state requires careful session handling
