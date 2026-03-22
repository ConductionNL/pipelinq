# My Work (Werkvoorraad) Specification

## Purpose

My Work provides a personal workload view aggregating all items assigned to the current user -- leads, requests, and optionally tasks from Procest. This is the user's daily productivity hub, showing what needs immediate attention, what is due soon, and what is upcoming. Items are organized into temporal groups (Overdue, Due This Week, Upcoming, No Due Date) and sorted by priority within each group.

The design follows the wireframe in DESIGN-REFERENCES.md section 3.5.

**Feature tier**: MVP (leads + requests, sorting, filtering, grouping), V1 (cross-app with Procest tasks, overdue highlighting, KPI widgets, quick actions, activity feed), Enterprise (saved filters, customizable layout, manager views)

---

## Requirements

### REQ-MW-010: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads and requests assigned to the current user. Only open items are shown by default, with a toggle to include completed items.

#### Scenario: View assigned leads and requests
- WHEN the current user navigates to "My Work"
- THEN the system MUST display all open leads and requests assigned to them
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)

#### Scenario: Only open items by default
- WHEN the user views My Work
- THEN only leads in non-closed stages and requests with non-terminal statuses MUST be shown
- AND closed/completed/rejected/converted items MUST NOT appear by default

#### Scenario: Toggle to show completed items
- WHEN the user enables the "Show completed" toggle
- THEN completed and closed items MUST also be displayed
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display
- WHEN the user views My Work
- THEN the header MUST display the total count and breakdown (e.g., "Leads (5) · Requests (3) — 8 items total")

#### Scenario: Empty workload
- WHEN the current user has no assigned items
- THEN the system MUST display "No items assigned to you"

---

### REQ-MW-020: Sorting [MVP]

Within each temporal group, items MUST be sorted by priority first, then by due date ascending.

#### Scenario: Default sort order within groups
- WHEN the user views My Work
- THEN within each group, items MUST be sorted by priority (urgent > high > normal > low), then by due date ascending

#### Scenario: Items without due date positioning
- WHEN items exist without a due date
- THEN they MUST be grouped in the "No Due Date" section

---

### REQ-MW-030: Temporal Grouping [MVP]

Items MUST be organized into four temporal groups displayed top to bottom: Overdue, Due This Week, Upcoming, No Due Date. Empty groups MUST be hidden.

#### Scenario: Overdue group
- WHEN a lead has expectedCloseDate in the past
- THEN it MUST appear in the "Overdue" group with "N days overdue" displayed

#### Scenario: Due This Week group
- WHEN a lead has expectedCloseDate within the current calendar week (today through Sunday)
- THEN it MUST appear in the "Due This Week" group

#### Scenario: Upcoming group
- WHEN a lead has expectedCloseDate after the current week
- THEN it MUST appear in the "Upcoming" group

#### Scenario: No Due Date group
- WHEN an item has no due date set
- THEN it MUST appear in the "No Due Date" group (last group)

#### Scenario: Section item counts
- WHEN a group contains items
- THEN the group header MUST display its item count (e.g., "Overdue (2)")

#### Scenario: Empty group behavior
- WHEN a group contains no items
- THEN it MUST be hidden (not shown as empty section)

---

### REQ-MW-040: Filtering [MVP]

The system MUST allow filtering by entity type: All (default), Leads, Requests.

#### Scenario: Filter by entity type
- WHEN the user selects a filter (All / Leads / Requests)
- THEN only matching items MUST be displayed
- AND the item count MUST update to reflect the filtered count
- AND grouping MUST be preserved

#### Scenario: Filter with empty result
- WHEN filtering produces no items
- THEN the system MUST display an empty state message

---

### REQ-MW-050: Overdue Item Highlighting [MVP]

Overdue items MUST be visually distinct with red indicators and "N days overdue" text.

#### Scenario: Overdue visual treatment
- WHEN an item is overdue
- THEN it MUST display a red visual indicator and "N days overdue" in red text

#### Scenario: Due today is not overdue
- WHEN an item is due today
- THEN it MUST appear in "Due This Week" (not Overdue)
- AND it SHOULD show "Due today" as a warning indicator

---

### REQ-MW-060: Item Navigation [MVP]

Each item MUST be clickable, navigating to the full detail view.

#### Scenario: Click item to navigate
- WHEN the user clicks a lead or request item
- THEN the system MUST navigate to the respective detail view

---

### REQ-MW-070: Cross-App Workload [V1]

The system MUST include items from Procest (cases and tasks assigned to the current user) in the My Work view.

#### Scenario: Include Procest tasks
- GIVEN the current user has 3 leads in Pipelinq and 2 tasks in Procest assigned to them
- WHEN they view My Work with cross-app integration enabled
- THEN all 5 items MUST appear in a unified list
- AND each Procest item MUST display a "Task" or "Case" entity type badge
- AND Procest items MUST follow the same sorting and grouping rules

#### Scenario: Filter includes Procest entity types
- GIVEN the cross-app workload is enabled
- WHEN the user views the filter options
- THEN the filter MUST include additional options: "Tasks" and/or "Cases"
- AND "All" MUST include Procest items

#### Scenario: Procest unavailable gracefully
- GIVEN Procest is not installed or not accessible
- WHEN the user views My Work
- THEN the system MUST display only Pipelinq items (leads and requests)
- AND the system MUST NOT show an error
- AND the cross-app filter options SHOULD be hidden

---

### REQ-MW-080: Item Card Layout [MVP]

Each item MUST follow a consistent card layout showing entity badge, title, stage/status, pipeline, value (leads), due date, and priority.

#### Scenario: Lead card in My Work
- WHEN a lead is displayed
- THEN it MUST show [LEAD] badge, title, stage, pipeline name, value, expected close date, and priority badge

#### Scenario: Request card in My Work
- WHEN a request is displayed
- THEN it MUST show [REQ] badge, title, status, due date, and priority badge

---

## ADDED Requirements

### REQ-MW-090: KPI Summary Widgets [V1]

The My Work view MUST display a row of key performance indicator (KPI) summary tiles at the top, giving the user an at-a-glance overview of their personal workload metrics. These tiles are scoped to the current user's assigned items only (not the team or organization totals shown on the Dashboard).

#### Scenario: Display personal KPI tiles
- GIVEN the user navigates to My Work
- WHEN the view has loaded
- THEN the system MUST display the following KPI tiles above the workload groups:
  - "Open Leads" -- count of leads in non-closed stages assigned to the user
  - "Open Requests" -- count of requests with status new or in_progress assigned to the user
  - "Overdue" -- count of items past their due date, displayed with error variant when count > 0
  - "Pipeline Value" -- sum of value fields on open leads assigned to the user, formatted as EUR currency
- AND each tile MUST be clickable, scrolling to or filtering the relevant items

#### Scenario: KPI tiles update with filter
- GIVEN the user selects the "Leads" entity type filter
- WHEN the filter is applied
- THEN the "Open Requests" tile MUST show 0 or be visually muted
- AND the "Pipeline Value" tile MUST reflect only filtered leads

#### Scenario: KPI tiles with zero values
- GIVEN the user has no overdue items
- WHEN the view loads
- THEN the "Overdue" tile MUST display 0 with default (non-error) styling

---

### REQ-MW-100: Quick Actions [V1]

The My Work view MUST provide quick action buttons that allow the user to create new items directly from the workspace without navigating away.

#### Scenario: Quick action buttons displayed
- GIVEN the user is on the My Work view
- WHEN the view has loaded
- THEN the header area MUST display quick action buttons for: "New Lead", "New Request", and "New Contact"
- AND each button MUST open the respective create dialog as a modal overlay

#### Scenario: Create lead via quick action
- GIVEN the user clicks the "New Lead" quick action button
- WHEN the lead create dialog opens
- THEN the assignee field MUST be pre-filled with the current user
- AND upon successful creation, the My Work list MUST refresh to include the new lead
- AND the user MUST be navigated to the new lead's detail view

#### Scenario: Create request via quick action
- GIVEN the user clicks the "New Request" quick action button
- WHEN the request create dialog opens
- THEN the assignee field MUST be pre-filled with the current user
- AND upon successful creation, the My Work list MUST refresh to include the new request

#### Scenario: Create contact via quick action
- GIVEN the user clicks the "New Contact" quick action button
- WHEN the contact create dialog opens and the user saves a contact
- THEN the user MUST be navigated to the new contact's detail view
- AND the My Work view MUST NOT change (contacts are not workload items)

---

### REQ-MW-110: Recent Activity Feed [V1]

The My Work view MUST include a collapsible recent activity feed showing the latest changes to items assigned to the current user, providing context on what has happened since their last visit.

#### Scenario: Display recent activity
- GIVEN the user has leads and requests assigned to them
- WHEN the user views My Work
- THEN the system MUST display a "Recent Activity" section showing the 10 most recently modified items assigned to the user
- AND each activity entry MUST show: entity type badge, item title, a relative timestamp (e.g., "2h ago", "3d ago"), and the type of change if available

#### Scenario: Activity entry navigation
- GIVEN the recent activity feed is displayed
- WHEN the user clicks on an activity entry
- THEN the system MUST navigate to the respective item's detail view

#### Scenario: Collapse activity feed
- GIVEN the recent activity section is displayed
- WHEN the user clicks the section header to collapse it
- THEN the activity feed MUST collapse, showing only the header
- AND the collapsed/expanded state MUST persist across page reloads via local storage

#### Scenario: Empty activity feed
- GIVEN the user has no recently modified items
- WHEN the user views the Recent Activity section
- THEN the system MUST display "No recent activity on your items"

---

### REQ-MW-120: Upcoming Follow-Ups [V1]

The My Work view MUST display upcoming follow-up actions scheduled for the current user, drawn from lead follow-up dates and request scheduled callback dates.

#### Scenario: Display follow-up reminders
- GIVEN the user has leads with a followUpDate field set to today or a future date
- WHEN the user views My Work
- THEN the system MUST display a "Follow-ups" section listing items with upcoming follow-up dates
- AND items MUST be sorted by follow-up date ascending (soonest first)
- AND each entry MUST show: entity badge, item title, follow-up date, and a relative indicator ("Today", "Tomorrow", "In 3 days")

#### Scenario: Follow-up due today highlighted
- GIVEN a lead has a followUpDate set to today
- WHEN the user views the Follow-ups section
- THEN the item MUST be highlighted with a warning indicator and the label "Follow-up today"

#### Scenario: Overdue follow-ups
- GIVEN a lead has a followUpDate in the past that has not been marked complete
- WHEN the user views the Follow-ups section
- THEN the item MUST appear at the top of the list with a red "Overdue follow-up" indicator

#### Scenario: No follow-ups scheduled
- GIVEN the user has no items with upcoming follow-up dates
- WHEN the user views My Work
- THEN the Follow-ups section MUST be hidden (not shown as empty)

---

### REQ-MW-130: Calendar Integration [V1]

The My Work view SHOULD display upcoming meetings and calendar events from the user's Nextcloud Calendar that are linked to CRM entities (clients, contacts, leads).

#### Scenario: Display upcoming meetings
- GIVEN the user has calendar events in the next 7 days that contain a link to a Pipelinq entity in the description or location field
- WHEN the user views My Work
- THEN the system MUST display a "Upcoming Meetings" section showing these calendar events
- AND each entry MUST show: meeting title, date/time, and the linked entity name (if resolvable)

#### Scenario: Calendar integration via Nextcloud CalDAV
- GIVEN the Nextcloud Calendar app is installed and the user has at least one calendar
- WHEN the system fetches upcoming meetings
- THEN the system MUST use the Nextcloud CalDAV API (OCP\Calendar\IManager) to retrieve events
- AND the system MUST only show events from the current user's calendars

#### Scenario: Calendar app not available
- GIVEN the Nextcloud Calendar app is not installed
- WHEN the user views My Work
- THEN the Upcoming Meetings section MUST be hidden
- AND no error MUST be displayed

#### Scenario: No upcoming meetings
- GIVEN the user has no linked calendar events in the next 7 days
- WHEN the user views My Work
- THEN the Upcoming Meetings section MUST be hidden

---

### REQ-MW-140: Notification Inbox Summary [V1]

The My Work view MUST display a summary of unread Pipelinq notifications, providing quick access to assignment changes, stage/status updates, and note additions.

#### Scenario: Display notification count
- GIVEN the user has unread Pipelinq notifications (assignments, stage changes, notes)
- WHEN the user views My Work
- THEN the header MUST display a notification badge showing the count of unread Pipelinq notifications
- AND clicking the badge MUST expand a notification dropdown or navigate to the Nextcloud notifications panel

#### Scenario: Notification types displayed
- GIVEN the user has unread notifications
- WHEN the notification dropdown is expanded
- THEN each notification MUST show: notification type icon (assignment, stage change, note), summary text, relative timestamp, and a link to the related item

#### Scenario: Mark notification as read
- GIVEN the notification dropdown is displayed with unread notifications
- WHEN the user clicks on a notification
- THEN the system MUST mark it as read via the Nextcloud Notifications API (OCP\Notification\IManager)
- AND the notification count badge MUST update

#### Scenario: No unread notifications
- GIVEN the user has no unread Pipelinq notifications
- WHEN the user views My Work
- THEN the notification badge MUST NOT be displayed

---

### REQ-MW-150: Saved Filters [Enterprise]

The system MUST allow users to save and recall filter combinations for the My Work view, enabling quick access to frequently used workload slices.

#### Scenario: Save current filter as preset
- GIVEN the user has applied a combination of filters (entity type, show completed, priority filter)
- WHEN the user clicks "Save Filter" and enters a name (e.g., "Urgent Leads Only")
- THEN the system MUST persist the filter configuration as a named preset in user preferences
- AND the preset MUST appear in a "Saved Filters" dropdown in the controls area

#### Scenario: Apply saved filter
- GIVEN the user has saved filters
- WHEN the user selects a saved filter from the dropdown
- THEN the system MUST apply all filter settings from the preset
- AND the item list and KPI tiles MUST update to reflect the filter

#### Scenario: Delete saved filter
- GIVEN the user has saved filters
- WHEN the user clicks the delete icon next to a saved filter
- THEN the system MUST remove the preset after confirmation
- AND the dropdown MUST update to reflect the removal

#### Scenario: Saved filters persist across sessions
- GIVEN the user has saved filters
- WHEN the user logs out and logs back in
- THEN all saved filters MUST still be available
- AND the system MUST store saved filters via the Pipelinq user settings API (/apps/pipelinq/api/user/settings)

---

### REQ-MW-160: Customizable Layout [Enterprise]

The My Work view MUST allow users to customize which sections are visible and their display order, enabling personalization of the workspace.

#### Scenario: Toggle section visibility
- GIVEN the user is on My Work and opens the layout customization panel (gear icon)
- WHEN the user toggles off the "Recent Activity" section
- THEN the Recent Activity section MUST be hidden from the view
- AND the preference MUST persist across page reloads

#### Scenario: Reorder sections
- GIVEN the user opens the layout customization panel
- WHEN the user drags the "Follow-ups" section above the "Workload Groups" section
- THEN the view MUST re-render with Follow-ups appearing before the workload groups
- AND the order MUST persist via user settings

#### Scenario: Reset to default layout
- GIVEN the user has customized the layout
- WHEN the user clicks "Reset to Default"
- THEN the system MUST restore the original section order and visibility
- AND the default layout MUST be: KPI tiles, Quick actions, Workload groups, Follow-ups, Recent Activity

---

### REQ-MW-170: Responsive Mobile View [MVP]

The My Work view MUST be fully usable on mobile devices with screen widths down to 320px, following progressive disclosure patterns to optimize for small screens.

#### Scenario: Mobile card layout
- GIVEN the user accesses My Work on a device with viewport width below 768px
- WHEN the view renders
- THEN work cards MUST stack vertically at full width
- AND the KPI tiles (V1) MUST wrap into a 2x2 grid
- AND the filter buttons MUST be accessible via a collapsible filter bar or dropdown

#### Scenario: Touch-friendly interaction targets
- GIVEN the user is on a touch device
- WHEN interacting with My Work
- THEN all interactive elements (cards, buttons, toggles) MUST have a minimum touch target of 44x44px (WCAG 2.5.5)
- AND cards MUST have sufficient padding for reliable tap targets

#### Scenario: Mobile quick actions
- GIVEN the user accesses My Work on a mobile device
- WHEN the view renders
- THEN the quick action buttons (V1) MUST collapse into a floating action button (FAB) with expandable options
- AND the FAB MUST be positioned in the bottom-right corner for thumb accessibility

#### Scenario: Narrow viewport group headers
- GIVEN the user views My Work on a viewport below 480px
- WHEN temporal groups are displayed
- THEN group headers MUST remain sticky at the top of the scroll container
- AND the item count badge MUST remain visible in the sticky header

---

### REQ-MW-180: Role-Based Content [V1]

The My Work view MUST adapt its content and available actions based on the user's role, distinguishing between KCC agents, team managers, and administrators.

#### Scenario: KCC agent view (default)
- GIVEN the user has no special Pipelinq admin or manager role
- WHEN they view My Work
- THEN the system MUST show only items assigned directly to them
- AND all KPI tiles MUST reflect personal metrics only
- AND the quick action buttons MUST be limited to "New Request" and "New Contact" (no "New Lead" unless the user has lead access)

#### Scenario: Team manager view
- GIVEN the user has a Nextcloud group role designated as team manager (configurable in Pipelinq settings)
- WHEN they view My Work
- THEN the system MUST show a "Team" toggle in the header that switches between "My Items" and "Team Items"
- AND when "Team Items" is selected, the view MUST display all items assigned to any member of the manager's team group
- AND KPI tiles MUST update to show team-aggregated metrics
- AND a "Team Members" mini-widget MUST show each team member's workload count

#### Scenario: Manager team member drill-down
- GIVEN the manager has selected "Team Items" view
- WHEN the manager clicks on a team member in the Team Members widget
- THEN the workload list MUST filter to show only that team member's assigned items
- AND a breadcrumb or back button MUST allow returning to the full team view

#### Scenario: Administrator view
- GIVEN the user is a Nextcloud admin or has the Pipelinq admin group
- WHEN they view My Work
- THEN the system MUST show an "Organization" toggle in addition to "My Items" and "Team"
- AND "Organization" MUST display all open items across all users
- AND KPI tiles MUST show organization-wide metrics

---

### REQ-MW-190: Auto-Refresh and Real-Time Updates [V1]

The My Work view MUST keep data current through periodic auto-refresh, ensuring the user always sees their latest workload state.

#### Scenario: Periodic auto-refresh
- GIVEN the user has My Work open
- WHEN 5 minutes have elapsed since the last data fetch
- THEN the system MUST automatically re-fetch all workload data in the background
- AND the view MUST update without full page reload or scroll position reset
- AND a subtle refresh indicator MUST be shown during the fetch (spinning icon, not blocking overlay)

#### Scenario: Manual refresh
- GIVEN the user is on My Work
- WHEN the user clicks the refresh button in the header
- THEN the system MUST immediately re-fetch all workload data
- AND the refresh button MUST show a spinning animation during the fetch

#### Scenario: Stale data indicator
- GIVEN the auto-refresh has failed (network error) and data is older than 10 minutes
- WHEN the user views My Work
- THEN the system MUST display a warning banner: "Data may be outdated. Last updated: [timestamp]"
- AND a retry button MUST be provided

---

### REQ-MW-200: Priority and Pipeline Filter Extensions [V1]

The My Work view MUST extend the basic entity type filter with additional filter dimensions for priority level and pipeline, enabling more precise workload slicing.

#### Scenario: Filter by priority
- GIVEN the user is on My Work
- WHEN the user selects a priority filter (e.g., "Urgent", "High")
- THEN only items matching the selected priority MUST be displayed
- AND the filter MUST support multi-select (e.g., "Urgent" + "High" simultaneously)
- AND the item count and KPI tiles MUST update to reflect the filtered set

#### Scenario: Filter by pipeline
- GIVEN the user has leads across multiple pipelines
- WHEN the user selects a pipeline filter (e.g., "Sales Pipeline")
- THEN only leads belonging to the selected pipeline MUST be displayed
- AND requests (which may not belong to a pipeline) MUST still appear unless explicitly filtered out

#### Scenario: Combined filters
- GIVEN the user selects entity type "Leads", priority "Urgent", and pipeline "Enterprise Sales"
- WHEN the filters are applied
- THEN only urgent leads in the Enterprise Sales pipeline assigned to the user MUST be displayed
- AND the active filter count MUST be shown as a badge on the filter control (e.g., "Filters (3)")

#### Scenario: Clear all filters
- GIVEN the user has applied multiple filters
- WHEN the user clicks "Clear filters"
- THEN all filters MUST reset to defaults (All entities, all priorities, all pipelines)
- AND the full workload MUST be displayed

---

## UI Layout Reference

The My Work view follows the wireframe in DESIGN-REFERENCES.md section 3.5:

```
Header: "My Work"  [Notification badge]  [Refresh]
Quick Actions: [+ New Lead] [+ New Request] [+ New Contact]
Subheader: "Leads (5) . Requests (3)  --  8 items total"

[KPI Tiles Row]
  [Open Leads: 5] [Open Requests: 3] [Overdue: 2] [Pipeline Value: EUR 125,000]

[Filter Bar]
  [All | Leads | Requests]  [Priority: All v]  [Pipeline: All v]  [Show completed toggle]  [Saved Filters v]

[Overdue (2)]
  - Item card (red highlight)
  - Item card (red highlight)

[Due This Week (3)]
  - Item card
  - Item card
  - Item card

[Upcoming (2)]
  - Item card
  - Item card

[No Due Date (1)]
  - Item card

[Follow-ups (collapsible)]
  - Follow-up entry (today)
  - Follow-up entry (tomorrow)

[Recent Activity (collapsible)]
  - Activity entry (2h ago)
  - Activity entry (1d ago)
  - Activity entry (3d ago)
```

- Each group has a header with the group name and item count
- Empty groups are hidden
- Items are full-width cards within each group
- The view is scrollable with all groups visible (no pagination)
- The layout MUST be responsive and stack properly on narrow viewports
- All interactive elements MUST be keyboard accessible (WCAG AA)
- KPI tiles, Follow-ups, and Recent Activity sections are V1 features
- Saved Filters and layout customization are Enterprise features

---

### Current Implementation Status

**Implemented:**
- Full `MyWork.vue` view exists at `src/views/MyWork.vue` with route `/my-work` (name `MyWork`) in `src/router/index.js`.
- **REQ-MW-010 (Personal Workload View):** Fully implemented. Fetches leads and requests assigned to `OC.currentUser` via OpenRegister API. Shows entity type badge (LEAD/REQ), title, stage/status, pipeline name, value (leads only), due date, and priority badge.
- **REQ-MW-020 (Sorting):** Implemented within each group -- items sorted by priority (urgent > high > normal > low), then by due date ascending.
- **REQ-MW-030 (Temporal Grouping):** Fully implemented. Four groups: Overdue, Due This Week, Upcoming, No Due Date. Empty groups are hidden. Group headers include item count.
- **REQ-MW-040 (Filtering):** Implemented. Filter buttons for All, Leads, Requests. Item counts update with filter.
- **REQ-MW-050 (Overdue Highlighting):** Implemented. Overdue items have red left border (`work-card--overdue`), red text showing "N days overdue". "Due today" warning is shown.
- **REQ-MW-060 (Item Navigation):** Implemented. Clicking a card navigates to `LeadDetail` or `RequestDetail` via `$router.push`. Cards are keyboard accessible with `tabindex="0"` and `@keydown.enter`.
- **REQ-MW-080 (Item Card Layout):** Implemented with entity badge, title, stage/status, pipeline name, value (leads), due date, and priority badge.
- Show completed toggle is implemented with visual distinction (opacity 0.6 via `work-card--completed` class).
- Item count breakdown in header ("Leads (5) . Requests (3) -- 8 items total") is implemented.
- Stale badge is shown on leads (14+ days since modification) using `isStale()` from `src/services/pipelineUtils.js`.

**Not yet implemented:**
- **REQ-MW-070 (Cross-App Workload with Procest):** Not implemented. No integration with Procest tasks/cases. The filter only shows All/Leads/Requests.
- **REQ-MW-090 (KPI Summary Widgets):** Not implemented. The Dashboard has KPI tiles (Open Leads, Open Requests, Pipeline Value, Overdue) but these are org-wide, not personal. My Work has no personal KPI summary.
- **REQ-MW-100 (Quick Actions):** Not implemented on My Work. The Dashboard has quick action buttons (New Lead, New Request, New Client) but My Work does not.
- **REQ-MW-110 (Recent Activity Feed):** Not implemented on My Work. A `RecentActivitiesWidget` exists as a Nextcloud dashboard widget (`src/views/widgets/RecentActivitiesWidget.vue`) and a "Requests by Status" widget exists on the Dashboard, but neither is scoped to the user's assigned items or integrated into My Work.
- **REQ-MW-120 (Upcoming Follow-Ups):** Not implemented. No followUpDate field is currently tracked on leads or requests.
- **REQ-MW-130 (Calendar Integration):** Not implemented. No CalDAV integration in My Work.
- **REQ-MW-140 (Notification Inbox Summary):** Not implemented on My Work. User notification settings exist (`src/views/settings/UserSettings.vue`) for assignments, stage changes, and notes, but no notification badge or summary is displayed in My Work.
- **REQ-MW-150 (Saved Filters):** Not implemented.
- **REQ-MW-160 (Customizable Layout):** Not implemented.
- **REQ-MW-170 (Responsive Mobile View):** Partially implemented. The view uses `flex-wrap` on controls and header, and `max-width: 900px` constrains the layout, but no explicit mobile breakpoints, sticky group headers, or FAB pattern exist.
- **REQ-MW-180 (Role-Based Content):** Not implemented. All users see the same view with only their own items. No team/manager/admin toggle.
- **REQ-MW-190 (Auto-Refresh):** Not implemented on My Work. The Dashboard has a 5-minute auto-refresh timer but My Work does not.
- **REQ-MW-200 (Priority and Pipeline Filter Extensions):** Not implemented. Only entity type filter exists. No priority or pipeline filter dimensions.
- No pagination -- fetches up to 200 leads and 200 requests via `_limit: 200`.
- Empty state message is generic ("No items assigned to you") rather than per-filter.

**Partial implementations:**
- Request overdue detection uses a fixed 30-day threshold from `requestedAt`, not the `expectedCloseDate` pattern. This may not match all user expectations.

### Standards & References
- WCAG AA: Keyboard accessibility implemented (tabindex, keydown.enter handlers). Color-only distinction is supplemented with text ("N days overdue", badges).
- Nextcloud OCP interfaces referenced: `OCP\Calendar\IManager` (REQ-MW-130), `OCP\Notification\IManager` (REQ-MW-140).
- User settings API: `/apps/pipelinq/api/user/settings` (REQ-MW-150, REQ-MW-160).
- No specific API standard applies -- this is a frontend aggregation view.

### Specificity Assessment
- The spec is well-defined for MVP requirements. All MVP scenarios have clear acceptance criteria.
- **V1 gap:** The cross-app Procest integration (REQ-MW-070) lacks detail on how Procest tasks will be discovered -- via direct API call, shared register, or cross-app event system.
- **V1 additions:** KPI widgets (REQ-MW-090), quick actions (REQ-MW-100), activity feed (REQ-MW-110), follow-ups (REQ-MW-120), calendar integration (REQ-MW-130), notification summary (REQ-MW-140), auto-refresh (REQ-MW-190), and extended filters (REQ-MW-200) are fully specified with acceptance scenarios.
- **Enterprise additions:** Saved filters (REQ-MW-150) and customizable layout (REQ-MW-160) provide power-user features with clear persistence requirements.
- **Role-based content (REQ-MW-180):** Specifies three tiers (agent, manager, admin) with team/org toggle, but the team group configuration mechanism in Pipelinq settings needs further definition.
- **Open question:** The spec groups requests in "No Due Date" or "Overdue" only. Should requests with a `requestedAt` date use that as their temporal grouping date (current implementation) or only use explicit due dates?
- **Open question:** REQ-MW-120 assumes a `followUpDate` field on leads -- this field may need to be added to the lead schema in OpenRegister.
