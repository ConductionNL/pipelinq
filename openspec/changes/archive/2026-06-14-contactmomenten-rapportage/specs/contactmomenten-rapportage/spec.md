# Spec: contactmomenten-rapportage

**App:** Pipelinq
**Change:** contactmomenten-rapportage
**Design ref:** openspec/changes/contactmomenten-rapportage/design.md
**Feature tier:** Core

---

## Requirements

### REQ-CR-001: KPI Dashboard

The system MUST display a dashboard with real-time KPI metrics computed from `contactmoment` objects.

#### Scenario: Dashboard displays four KPI cards

- GIVEN the user navigates to `/rapportage`
- WHEN the dashboard loads with date range "today"
- THEN four `CnStatsBlock` KPI cards MUST appear with the following labels:
  - **Total Contacts** — count of all contactmomenten in the selected period
  - **FCR %** — percentage of contactmomenten with `outcome = 'opgelost'`
  - **Avg Handling Time** — average of all `duration` values formatted as MM:SS
  - **SLA Compliance %** — weighted average SLA compliance across all channels

#### Scenario: Dashboard auto-refreshes every 60 seconds

- GIVEN the rapportage dashboard is open
- WHEN 60 seconds have elapsed since the last data load
- THEN all KPI values, charts, and tables MUST refresh automatically
- AND no user interaction MUST be required
- AND the refresh MUST NOT cause a visible page reload

#### Scenario: Empty state when no data in selected period

- GIVEN no contactmomenten exist for the selected date range
- WHEN the dashboard loads
- THEN all KPI cards MUST show `0` or `N/A`
- AND a `CnEmptyState` message MUST appear below the charts indicating no data is available for the period

#### Scenario: Loading state during data fetch

- GIVEN the user selects a new date range
- WHEN the API call is in-flight
- THEN the KPI cards MUST show a loading indicator
- AND the charts MUST be replaced with skeleton placeholders
- AND the user MUST be able to interact with the date range selector while loading

---

### REQ-CR-002: Channel Distribution Analytics

The system MUST visualize how contactmomenten are distributed across communication channels.

#### Scenario: Channel distribution donut chart

- GIVEN contactmomenten exist across channels: telefoon (40), email (30), balie (15), chat (10), social (3), brief (2)
- WHEN the user views the `ChannelAnalytics` view
- THEN a donut `CnChartWidget` MUST show each channel as a percentage slice
- AND each channel MUST have a distinct color
- AND hovering a slice MUST show the channel name and exact count

#### Scenario: Channel volume bar chart

- GIVEN contactmomenten across multiple channels
- WHEN the user views ChannelAnalytics
- THEN a bar `CnChartWidget` MUST show one bar per channel with the contact count as height
- AND the X-axis MUST label each channel in Dutch (Telefoon, E-mail, Balie, Chat, Social, Brief)

#### Scenario: Channel trend over selected period

- GIVEN contactmomenten recorded over the selected date range
- WHEN the user views the channel trend chart
- THEN a line `CnChartWidget` MUST show one series per active channel
- AND each day (or week for ranges > 30 days) MUST appear on the X-axis
- AND channels with zero contacts in the period MUST be omitted from the legend

#### Scenario: Per-channel SLA compliance table

- GIVEN SLA targets configured for telefoon (90% within 30 s) and email (95% within 8 h)
- WHEN the user views the channel analytics table
- THEN a `CnDataTable` MUST show one row per channel with: channel name, contact count, SLA target, compliance %
- AND a `CnStatusBadge` MUST show green if compliance % ≥ target, red otherwise
- AND channels without a configured SLA target MUST show "—" in the SLA columns

---

### REQ-CR-003: Agent Performance Overview

The system MUST display per-agent statistics for contactmomenten handled.

#### Scenario: Agent performance table default view

- GIVEN agents uid:agent.bakker (45 contacts), uid:agent.jansen (32 contacts), uid:agent.de_vries (12 contacts) have handled contactmomenten in the selected period
- WHEN the user navigates to `/rapportage/agenten`
- THEN a `CnDataTable` MUST show one row per agent with:
  - Agent display name
  - Total contacts handled
  - FCR % (contacts with outcome 'opgelost' / total contacts × 100)
  - Avg handling time (formatted as MM:SS)
- AND the table MUST be sorted by total contacts descending by default

#### Scenario: Table is sortable by column

- GIVEN the agent performance table is displaying 3 agents
- WHEN the user clicks the "FCR %" column header
- THEN the table MUST re-sort by FCR % descending
- AND clicking again MUST reverse to ascending

#### Scenario: Agents without contacts excluded

- GIVEN agent uid:agent.new has 0 contactmomenten in the selected period
- WHEN the user views the agent performance table
- THEN uid:agent.new MUST NOT appear in the table
- AND only agents with ≥ 1 contactmoment in the period MUST be shown

---

### REQ-CR-004: SLA Configuration and Compliance Monitoring

The system MUST allow administrators to configure SLA targets and display compliance against those targets.

#### Scenario: Admin views current SLA configuration

- GIVEN SLA targets are stored in IAppConfig
- WHEN an admin accesses the SLA configuration section
- THEN all configured channels MUST be listed with their current target values:
  - telefoon: wait time X seconds, target Y%
  - email: response time X hours, target Y%
  - balie: wait time X seconds, target Y%
  - chat: response time X seconds, target Y%

#### Scenario: Admin updates an SLA target

- GIVEN the current telefoon wait time is 30 seconds
- WHEN the admin changes it to 45 seconds and saves
- THEN `PUT /api/rapportage/sla` MUST return HTTP 200
- AND the new value MUST be persisted in `IAppConfig`
- AND the SLA compliance calculation on the next dashboard refresh MUST use 45 seconds as the target

#### Scenario: Non-admin user cannot update SLA targets

- GIVEN a regular Pipelinq user (not in admin group)
- WHEN they attempt `PUT /api/rapportage/sla`
- THEN the endpoint MUST return HTTP 403
- AND the response body MUST contain `{"message": "Insufficient permissions"}`
- AND the admin SLA configuration controls MUST NOT be rendered in the UI for non-admin users

#### Scenario: SLA compliance dashboard card reflects targets

- GIVEN telefoon SLA target is 90% within 30 seconds
- AND 85 of 100 telefoon contacts this week had `channelMetadata.waitSeconds` ≤ 30
- WHEN the user views the KPI dashboard
- THEN the SLA Compliance card MUST show ≤ 90% (below target) with a warning color
- AND if ≥ 90%, the card MUST show a success color

---

### REQ-CR-005: Date Range Filtering

The system MUST support date range filtering applied uniformly across all reporting views.

#### Scenario: Select predefined date range — today

- GIVEN the user is on the rapportage dashboard
- WHEN they select "Vandaag" from the date range picker
- THEN all metrics, charts, and tables MUST show only contactmomenten with `contactedAt` on today's date
- AND the selected range MUST be displayed in the filter bar

#### Scenario: Select predefined date range — this month

- GIVEN the user selects "Deze maand"
- THEN all data MUST cover from the first day of the current calendar month to today
- AND the API call parameters `from` and `to` MUST reflect these dates in ISO 8601 format

#### Scenario: Select custom date range

- GIVEN the user selects "Aangepast" and enters 2026-04-01 to 2026-04-15
- THEN all data MUST be filtered to that range
- AND if `to` is in the future, the filter MUST cap at today's date
- AND the selected custom range MUST be shown in the UI as "01-04-2026 – 15-04-2026"

#### Scenario: Date range persists across sub-pages

- GIVEN the user has set "Deze week" on RapportageDashboard
- WHEN they navigate to `/rapportage/kanalen`
- THEN ChannelAnalytics MUST load with the same "Deze week" date range already applied
- AND the date range selector MUST show "Deze week" as the active selection

---

### REQ-CR-006: CSV Export

The system MUST support CSV export of filtered contactmomenten data.

#### Scenario: Export all contactmomenten for selected period

- GIVEN the user has selected date range "deze maand"
- WHEN they click the export button and confirm CSV format via `CnMassExportDialog`
- THEN a CSV file download MUST start
- AND the CSV MUST include the columns: `contactedAt`, `channel`, `agent`, `subject`, `outcome`, `duration`, `client`
- AND the filename MUST follow the pattern `contactmomenten_YYYY-MM-DD_YYYY-MM-DD.csv`

#### Scenario: Export respects active channel filter

- GIVEN the user has filtered to channel = `telefoon` in ChannelAnalytics
- WHEN they export
- THEN the CSV MUST only contain contactmomenten where `channel = 'telefoon'`
- AND the filename MUST reflect the active filter (e.g., `contactmomenten_telefoon_2026-04-01_2026-04-15.csv`)

#### Scenario: Export uses platform CnMassExportDialog

- GIVEN the user clicks export on any reporting view
- THEN the standard `CnMassExportDialog` MUST be used
- AND no custom file upload handler, export controller, or download endpoint MUST be built
- AND the export button MUST be accessible via keyboard (WCAG AA)
