# Contactmomenten Rapportage Specification

## Purpose

Contactmomenten rapportage provides management dashboards, KPI monitoring, and reporting on all registered contact moments. This enables KCC managers to monitor service levels, identify bottlenecks, and optimize staffing. This is the second most demanded capability: **98% of klantinteractie-tenders** (51/52) require contact moment reporting with KPIs and SLA monitoring.

**Standards**: VNG Klantinteracties (reporting on `Contactmoment` entities), Common Ground (API-based data extraction)
**Feature tier**: MVP (core KPIs), V1 (advanced analytics), Enterprise (BI integration)
**Tender frequency**: 51/52 (98%)

## Data Model

Reporting is built on aggregated contactmoment data from OpenRegister:
- **Contactmoment**: Source entity with channel, duration, timestamp, agent, outcome, linked client/zaak
- **KPI calculations**: Derived from contactmoment properties (averages, counts, percentages)
- **SLA targets**: Configurable thresholds stored as application settings

## Requirements

---

### Requirement: KPI Dashboard

The system MUST provide a real-time dashboard showing key performance indicators for the KCC.

**Feature tier**: MVP

#### Scenario: Display daily KPI overview

- GIVEN 150 contactmomenten have been registered today across all agents
- WHEN a KCC manager opens the rapportage dashboard
- THEN the system MUST display:
  - Total contacts today (150)
  - Contacts per channel (telefoon: 95, e-mail: 30, balie: 15, chat: 10)
  - Average handling time (4:23)
  - Current queue length (3 waiting)
  - Agents currently active (8)
- AND each KPI MUST show a trend indicator (up/down) compared to the same day last week

#### Scenario: Display first-call resolution rate

- GIVEN 150 contactmomenten today, of which 112 were resolved without routing to backoffice
- WHEN the KCC manager views the dashboard
- THEN the system MUST display first-call resolution rate as 74.7%
- AND the rate MUST be compared against the configured target (e.g., 80%)
- AND if below target, the KPI MUST be highlighted in orange/red

#### Scenario: Display SLA compliance

- GIVEN an SLA target of "90% of phone calls answered within 30 seconds"
- AND 95 phone calls today, of which 80 were answered within 30 seconds
- WHEN the KCC manager views the dashboard
- THEN the system MUST display SLA compliance as 84.2% (below 90% target)
- AND the SLA gauge MUST visually indicate below-target status

---

### Requirement: Channel Analytics

The system MUST provide detailed analytics per contact channel, enabling managers to understand channel distribution and trends.

**Feature tier**: MVP

#### Scenario: Channel distribution chart

- GIVEN contactmomenten data for the past 30 days
- WHEN the manager views the channel analytics section
- THEN the system MUST display a chart showing contact volume per channel over time
- AND the chart MUST support daily, weekly, and monthly granularity
- AND each channel MUST be color-coded consistently across all charts

#### Scenario: Channel comparison table

- GIVEN contactmomenten data for the current month
- WHEN the manager views the channel comparison
- THEN the system MUST display a table with per channel: total contacts, average handling time, first-call resolution rate, and SLA compliance
- AND the table MUST be sortable by any column

---

### Requirement: Wait Time Monitoring

The system MUST track and report on wait times (wachttijden) for incoming contacts, particularly phone and queue-based channels.

**Feature tier**: MVP

#### Scenario: Real-time queue statistics

- GIVEN 5 contacts are currently waiting in the phone queue
- WHEN the KCC manager views the real-time panel
- THEN the system MUST display: number waiting (5), longest wait time, average wait time, and estimated wait time for new callers
- AND the display MUST update in real-time (at least every 30 seconds)

#### Scenario: Historical wait time report

- GIVEN wait time data for the past week
- WHEN the manager generates a wait time report
- THEN the system MUST display: average wait time per day, peak hours with longest waits, percentage of contacts within SLA wait target
- AND the report MUST identify patterns (e.g., "Monday mornings consistently exceed SLA")

---

### Requirement: Agent Performance

The system MUST provide per-agent statistics to support team management and coaching.

**Feature tier**: V1

#### Scenario: Individual agent statistics

- GIVEN agent "Medewerker A" has handled 25 contacts today
- WHEN the KCC manager views agent performance
- THEN the system MUST display for this agent: contacts handled (25), average handling time, first-call resolution rate, and contacts per hour
- AND the agent's metrics MUST be comparable to team averages

#### Scenario: Team overview

- GIVEN a KCC team of 10 agents
- WHEN the KCC manager views the team overview
- THEN the system MUST display a ranked list of agents by contacts handled
- AND each agent row MUST show: name, contacts today, avg handling time, FCR rate, and current status (beschikbaar/in gesprek/pauze)
- AND the overview MUST highlight agents significantly above or below team averages

---

### Requirement: Trend Reporting

The system MUST provide trend reports over configurable time periods, enabling managers to identify patterns and plan capacity.

**Feature tier**: V1

#### Scenario: Monthly trend report

- GIVEN contactmomenten data spanning 6 months
- WHEN the manager generates a monthly trend report
- THEN the system MUST display per month: total contacts, contacts per channel, average handling time, FCR rate, and SLA compliance
- AND the report MUST include trend lines showing direction of change

#### Scenario: Peak hours analysis

- GIVEN contactmomenten data for the past 4 weeks
- WHEN the manager views the peak hours analysis
- THEN the system MUST display a heatmap showing contact volume by day-of-week and hour-of-day
- AND peak hours MUST be highlighted to support staffing decisions

---

### Requirement: Export and BI Integration

The system MUST support exporting report data for use in external BI tools and management presentations.

**Feature tier**: MVP

#### Scenario: Export dashboard data as CSV

- GIVEN the KCC manager is viewing the monthly trend report
- WHEN they click "Exporteer als CSV"
- THEN the system MUST generate a CSV file with all displayed data points including headers
- AND the file MUST be immediately downloadable

#### Scenario: Export dashboard as PDF

- GIVEN the KCC manager is viewing the daily KPI dashboard
- WHEN they click "Exporteer als PDF"
- THEN the system MUST generate a PDF with the dashboard layout, charts, and KPI values
- AND the PDF MUST include the report date and generation timestamp

#### Scenario: Scheduled report delivery

- GIVEN the KCC manager configures a weekly report to be delivered every Monday at 08:00
- WHEN Monday 08:00 arrives
- THEN the system MUST generate the report covering the previous week
- AND deliver it via Nextcloud notification with a download link
- AND the schedule MUST be configurable per report type

---

### Requirement: SLA Configuration

The system MUST allow administrators to configure SLA targets per channel and contact type.

**Feature tier**: MVP

#### Scenario: Configure phone SLA target

- GIVEN an administrator accessing the SLA configuration
- WHEN they set the phone channel SLA to "90% answered within 30 seconds" and average handling time target to "5 minutes"
- THEN the system MUST store these targets as application configuration
- AND the dashboard MUST use these targets for SLA compliance calculations
- AND changes MUST take effect immediately on the dashboard

#### Scenario: Configure per-channel targets

- GIVEN SLA targets need to differ per channel
- WHEN the administrator configures: telefoon (30s wait, 5min handle), e-mail (8h response, 24h resolution), balie (5min wait, 10min handle), chat (30s response, 10min handle)
- THEN the system MUST store separate targets per channel
- AND the dashboard MUST evaluate each channel against its own targets
