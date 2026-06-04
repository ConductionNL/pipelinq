---
status: draft
---

# Contactmomenten Rapportage Specification

## Purpose

Contactmomenten rapportage provides KPI dashboards, channel analytics, SLA monitoring, and agent performance reporting for all registered contact moments in Pipelinq. This enables KCC managers to monitor service levels, identify bottlenecks, and manage agent workload using live data from OpenRegister.

**Demand signal**: 98% of klantinteractie-tenders (51/52) require contact moment reporting with KPI and SLA monitoring.
**Feature tier**: MVP (KPI dashboard, channel analytics, SLA config, CSV export); V1 (agent trends, PDF, scheduled delivery)
**Standards**: VNG Klantinteracties (Contactmoment entity), ISO 18295 (Customer contact centres), Common Ground (API-based data)

## Data Model

Reporting reads from the existing OpenRegister entities (ADR-000):

- **contactmoment**: Primary data source. Properties used: `channel`, `duration`, `contactedAt`, `agent`, `outcome`, `channelMetadata`
- **agentProfile**: Agent metadata. Properties used: `userId`, `isAvailable`

KPI calculations are derived values — NOT stored as OpenRegister objects. SLA targets are stored as `IAppConfig` entries under the `pipelinq` namespace, NOT in OpenRegister.

---

## ADDED Requirements

---

### Requirement: KPI Dashboard (REQ-RAP-001)

The system MUST provide a real-time dashboard showing key performance indicators for KCC management.

**Feature tier**: MVP

#### Scenario: Display daily KPI metrics

- GIVEN contactmomenten have been registered for the selected date
- WHEN a KCC manager opens the rapportage dashboard
- THEN the system MUST display four KPI widgets using `CnStatsBlock`:
  - **Totaal contacts** — count of all contactmomenten for the selected date
  - **FCR Rate** — percentage of contactmomenten resolved without referral (outcome does not contain "doorverwezen")
  - **SLA Compliance** — percentage of phone contacts answered within the configured wait-time target
  - **Gem. afhandeltijd** — average duration in minutes across all contactmomenten with a non-null `duration`
- AND each widget MUST show the metric value prominently with unit (e.g., "74.7%", "4m 23s")

#### Scenario: FCR rate calculation excludes empty outcomes

- GIVEN 150 contactmomenten today, of which 112 have outcomes NOT containing "doorverwezen" and NOT empty
- WHEN the KCC manager views the dashboard FCR widget
- THEN the system MUST display FCR as 74.7% (112/150)
- AND contactmomenten with empty `outcome` MUST be excluded from both numerator and denominator

#### Scenario: SLA gauge color coding

- GIVEN a phone SLA target of 90% with warning at 85% and critical at 80%
- AND current SLA compliance is 84.2% (below warning threshold)
- WHEN the KCC manager views the SLA compliance widget
- THEN the SLA gauge MUST display in orange (between warning and critical thresholds)
- AND the configured target percentage MUST be shown alongside the current value

#### Scenario: Dashboard auto-refresh

- GIVEN the KCC manager has the rapportage dashboard open
- WHEN 60 seconds have elapsed since the last data fetch
- THEN the dashboard MUST automatically fetch updated data from the API
- AND the refresh MUST NOT cause visible flickering or layout shifts
- AND a "Laatste update: HH:MM" timestamp MUST be updated after each refresh

#### Scenario: Dashboard empty state

- GIVEN no contactmomenten have been registered for the selected date
- WHEN a KCC manager opens the rapportage dashboard
- THEN all KPI widgets MUST display "0" or "N/A" rather than an error
- AND the empty state MUST NOT prevent navigation to other rapportage sections

---

### Requirement: Channel Analytics (REQ-RAP-002)

The system MUST provide detailed analytics per contact channel, enabling managers to understand contact distribution and trends.

**Feature tier**: MVP

#### Scenario: Channel distribution chart

- GIVEN contactmomenten data for the selected date range (default: last 30 days)
- WHEN the manager views the channel analytics page
- THEN the system MUST display a bar chart (via `CnChartWidget` with ApexCharts) showing contact volume per channel over time
- AND each channel (telefoon, e-mail, balie, chat) MUST be color-coded consistently

#### Scenario: Granularity toggle

- GIVEN the manager is viewing the channel distribution chart
- WHEN they click "Dag", "Week", or "Maand" toggle buttons
- THEN the chart MUST re-aggregate data at the selected granularity without a full page reload
- AND the selected granularity button MUST be visually indicated as active

#### Scenario: Channel comparison table

- GIVEN contactmomenten data for the selected date range
- WHEN the manager views the channel comparison section
- THEN the system MUST display a `CnDataTable` with columns: Kanaal, Totaal, Gem. afhandeltijd, FCR Rate, SLA%
- AND the table MUST be sortable by any column
- AND each row MUST represent one communication channel

---

### Requirement: SLA Configuration (REQ-RAP-003)

The system MUST allow administrators to configure SLA targets per channel. Non-admin users MUST be able to read SLA configuration but NOT modify it.

**Feature tier**: MVP

#### Scenario: Read SLA configuration

- GIVEN SLA targets are stored in `IAppConfig` under the `pipelinq` namespace
- WHEN any authenticated user requests `GET /api/rapportage/sla`
- THEN the system MUST return the current SLA targets for all channels including target percentage, warning threshold, and critical threshold

#### Scenario: Update phone channel SLA target

- GIVEN an administrator is updating SLA configuration
- WHEN they submit `PUT /api/rapportage/sla` with `{ "sla_telefoon_wait_seconds": 30, "sla_telefoon_target_percent": 90, "sla_telefoon_warn_percent": 85, "sla_telefoon_critical_percent": 80 }`
- THEN the system MUST store these values in `IAppConfig` under the `pipelinq` namespace
- AND the dashboard MUST use the updated targets on the next data fetch without requiring a page reload

#### Scenario: SLA configuration validation

- GIVEN an administrator submits SLA configuration with invalid values
- WHEN `sla_telefoon_target_percent` is outside the range 1–100
- THEN the system MUST return a 422 error with a descriptive message
- AND the previous valid configuration MUST remain unchanged

#### Scenario: Per-channel SLA targets

- GIVEN SLA requirements differ by channel
- WHEN the administrator configures separate targets for telefoon, e-mail, balie, and chat
- THEN each channel MUST be evaluated against its own configured target on the dashboard
- AND the SLA compliance widget MUST show compliance for the phone channel (primary SLA indicator)

---

### Requirement: Agent Performance Overview (REQ-RAP-004)

The system MUST provide per-agent statistics to support team management, sourced from the `agent` field on contactmoment objects.

**Feature tier**: MVP (overview); V1 (trends over time)

#### Scenario: Individual agent statistics table

- GIVEN contactmomenten registered for the selected date with non-null `agent` field values
- WHEN the KCC manager views the agent performance page
- THEN the system MUST display a `CnDataTable` with rows per agent showing: Agent naam, Contacts vandaag, Gem. afhandeltijd, FCR Rate
- AND the table MUST be sorted by "Contacts vandaag" (descending) by default
- AND agent identity MUST be resolved from the `userId` field of `agentProfile` objects matching the contactmoment `agent` field

#### Scenario: Workload distribution chart

- GIVEN contactmomenten data for the selected date
- WHEN the manager views the workload distribution section
- THEN the system MUST display a horizontal bar chart showing contacts per agent
- AND agents with a contacts count >20% above the team average MUST be visually flagged (e.g., highlighted bar or icon)

#### Scenario: Agent performance empty state

- GIVEN no contactmomenten with a non-null `agent` field exist for the selected date
- WHEN the manager views the agent performance page
- THEN the system MUST display an empty state message: "Geen agentstatistieken beschikbaar voor deze datum"
- AND the page MUST NOT display an error

---

### Requirement: CSV Export (REQ-RAP-005)

The system MUST support exporting any report view as a CSV file for use in external spreadsheet tools.

**Feature tier**: MVP

#### Scenario: Export KPI data as CSV

- GIVEN the KCC manager is viewing the rapportage dashboard
- WHEN they click "Exporteer als CSV"
- THEN the browser MUST download a file named `rapportage-kpis-YYYY-MM-DD.csv`
- AND the file MUST use semicolon (`;`) as separator and begin with a UTF-8 BOM (`\xEF\xBB\xBF`) for correct display in Dutch Excel
- AND the file MUST include a header row with Dutch column names

#### Scenario: Export channel distribution as CSV

- GIVEN the manager is viewing the channel analytics page
- WHEN they click "Exporteer als CSV"
- THEN the browser MUST download a file with channel distribution data for the selected date range and granularity
- AND the file MUST include columns: Datum, Kanaal, Aantal contacts, Gem. afhandeltijd (min), FCR Rate (%), SLA Compliance (%)

#### Scenario: Export agent performance as CSV

- GIVEN the manager is viewing the agent performance page
- WHEN they click "Exporteer als CSV"
- THEN the browser MUST download a file with one row per agent containing: Agent, Contacts, Gem. afhandeltijd (min), FCR Rate (%)

---

### Requirement: Reporting Navigation (REQ-RAP-006)

The system MUST provide a dedicated "Rapportage" entry in the main sidebar navigation.

**Feature tier**: MVP

#### Scenario: Rapportage nav item visible

- GIVEN a KCC manager is logged in to Pipelinq
- WHEN they view the main sidebar (`MainMenu.vue`)
- THEN a "Rapportage" navigation item with a chart icon MUST be visible
- AND clicking it MUST navigate to `/rapportage` (the KPI dashboard)

#### Scenario: Rapportage sub-navigation

- GIVEN the manager is on any rapportage sub-page
- WHEN they view the navigation
- THEN navigation to "Kanalen" (`/rapportage/kanalen`) and "Agenten" (`/rapportage/agenten`) MUST be accessible

#### Scenario: Active route highlighting

- GIVEN the manager is on the `/rapportage/kanalen` page
- WHEN the sidebar renders
- THEN the "Rapportage" nav item MUST appear in the active/selected state per Nextcloud `NcAppNavigationItem` conventions
