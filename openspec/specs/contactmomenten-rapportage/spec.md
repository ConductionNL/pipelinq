# Contactmomenten Rapportage Specification

## Purpose

Contactmomenten rapportage provides management dashboards, KPI monitoring, and reporting on all registered contact moments. This enables KCC managers to monitor service levels, identify bottlenecks, and optimize staffing. This is the second most demanded capability: **98% of klantinteractie-tenders** (51/52) require contact moment reporting with KPIs and SLA monitoring.

**Standards**: VNG Klantinteracties (reporting on `Contactmoment` entities), Common Ground (API-based data extraction), ISO 18295 (Customer contact centres)
**Feature tier**: MVP (core KPIs), V1 (advanced analytics), Enterprise (BI integration)
**Tender frequency**: 51/52 (98%)

## Data Model

Reporting is built on aggregated contactmoment data from OpenRegister:
- **Contactmoment**: Source entity with channel, duration, timestamp, agent, outcome, linked client/zaak
- **KPI calculations**: Derived from contactmoment properties (averages, counts, percentages)
- **SLA targets**: Configurable thresholds stored as application settings via `IAppConfig`
- **Report snapshots**: Cached aggregations for historical trend analysis

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
- AND KPI widgets MUST use `CnStatsBlock` from the shared component library for consistent display

#### Scenario: Display first-call resolution rate

- GIVEN 150 contactmomenten today, of which 112 were resolved without routing to backoffice
- WHEN the KCC manager views the dashboard
- THEN the system MUST display first-call resolution rate as 74.7%
- AND the rate MUST be compared against the configured target (e.g., 80%)
- AND if below target, the KPI MUST be highlighted in orange/red
- AND the FCR calculation MUST exclude contactmomenten with type "informatief" (informational only)

#### Scenario: Display SLA compliance

- GIVEN an SLA target of "90% of phone calls answered within 30 seconds"
- AND 95 phone calls today, of which 80 were answered within 30 seconds
- WHEN the KCC manager views the dashboard
- THEN the system MUST display SLA compliance as 84.2% (below 90% target)
- AND the SLA gauge MUST visually indicate below-target status using color coding (green >= target, orange within 5%, red > 5% below)

#### Scenario: Dashboard auto-refresh

- GIVEN the KCC manager has the rapportage dashboard open
- WHEN 60 seconds have elapsed since the last refresh
- THEN the dashboard MUST automatically fetch updated data from the OpenRegister API
- AND the refresh MUST NOT cause visible flickering or layout shifts
- AND a "Last updated" timestamp MUST be displayed

#### Scenario: Dashboard empty state

- GIVEN no contactmomenten have been registered today
- WHEN a KCC manager opens the rapportage dashboard
- THEN the system MUST display all KPI widgets with value "0" or "N/A"
- AND a message MUST indicate "Nog geen contactmomenten geregistreerd vandaag"
- AND historical trend data MUST still be accessible

---

### Requirement: Channel Analytics

The system MUST provide detailed analytics per contact channel, enabling managers to understand channel distribution and trends.

**Feature tier**: MVP

#### Scenario: Channel distribution chart

- GIVEN contactmomenten data for the past 30 days
- WHEN the manager views the channel analytics section
- THEN the system MUST display a chart showing contact volume per channel over time
- AND the chart MUST support daily, weekly, and monthly granularity via toggle buttons
- AND each channel MUST be color-coded consistently across all charts
- AND the chart MUST use the ApexCharts library from `@conduction/nextcloud-vue`

#### Scenario: Channel comparison table

- GIVEN contactmomenten data for the current month
- WHEN the manager views the channel comparison
- THEN the system MUST display a table with per channel: total contacts, average handling time, first-call resolution rate, and SLA compliance
- AND the table MUST be sortable by any column
- AND each metric cell MUST show a comparison indicator versus the previous month (arrow up/down with percentage)

#### Scenario: Channel shift analysis

- GIVEN contactmomenten data spanning 12 months
- WHEN the manager views the channel shift analysis
- THEN the system MUST display how channel distribution has changed over time (e.g., telefoon declining from 70% to 55%, chat increasing from 5% to 15%)
- AND the analysis MUST highlight channels with statistically significant shifts (>5% change)

---

### Requirement: Wait Time Monitoring

The system MUST track and report on wait times (wachttijden) for incoming contacts, particularly phone and queue-based channels.

**Feature tier**: MVP

#### Scenario: Real-time queue statistics

- GIVEN 5 contacts are currently waiting in the phone queue
- WHEN the KCC manager views the real-time panel
- THEN the system MUST display: number waiting (5), longest wait time, average wait time, and estimated wait time for new callers
- AND the display MUST update at configurable intervals (default: every 30 seconds)
- AND queue data MUST be fetched via the external PBX integration (OpenConnector source)

#### Scenario: Historical wait time report

- GIVEN wait time data for the past week
- WHEN the manager generates a wait time report
- THEN the system MUST display: average wait time per day, peak hours with longest waits, percentage of contacts within SLA wait target
- AND the report MUST identify patterns (e.g., "Monday mornings consistently exceed SLA")
- AND the report MUST provide staffing recommendations based on identified patterns

#### Scenario: Wait time SLA breach alert

- GIVEN a configured SLA of "90% of phone calls answered within 30 seconds"
- AND the current real-time SLA compliance drops below 80%
- WHEN the threshold is breached
- THEN the system MUST display a prominent warning on the dashboard
- AND a Nextcloud notification MUST be sent to configured KCC managers
- AND the alert MUST include the current SLA percentage and the number of contacts waiting

---

### Requirement: Agent Performance

The system MUST provide per-agent statistics to support team management and coaching.

**Feature tier**: V1

#### Scenario: Individual agent statistics

- GIVEN agent "Medewerker A" has handled 25 contacts today
- WHEN the KCC manager views agent performance
- THEN the system MUST display for this agent: contacts handled (25), average handling time, first-call resolution rate, and contacts per hour
- AND the agent's metrics MUST be comparable to team averages via inline comparison indicators
- AND the data MUST be sourced from contactmomenten where the `medewerker` field matches the agent's Nextcloud user UID

#### Scenario: Team overview

- GIVEN a KCC team of 10 agents
- WHEN the KCC manager views the team overview
- THEN the system MUST display a ranked list of agents by contacts handled
- AND each agent row MUST show: name, contacts today, avg handling time, FCR rate, and current status (beschikbaar/in gesprek/pauze)
- AND the overview MUST highlight agents significantly above or below team averages (>1 standard deviation)

#### Scenario: Agent workload distribution

- GIVEN a KCC team of 10 agents with varying contact loads
- WHEN the manager views the workload distribution
- THEN the system MUST display a bar chart showing contacts per agent
- AND the chart MUST indicate whether workload is balanced or skewed
- AND agents with >20% above average workload MUST be flagged for potential burnout risk

#### Scenario: Agent performance over time

- GIVEN agent "Medewerker A" has been active for the past 30 days
- WHEN the KCC manager views the agent's performance trend
- THEN the system MUST display a line chart showing daily contacts handled, FCR rate, and average handling time
- AND the chart MUST indicate whether the agent's performance is improving or declining

---

### Requirement: Trend Reporting

The system MUST provide trend reports over configurable time periods, enabling managers to identify patterns and plan capacity.

**Feature tier**: V1

#### Scenario: Monthly trend report

- GIVEN contactmomenten data spanning 6 months
- WHEN the manager generates a monthly trend report
- THEN the system MUST display per month: total contacts, contacts per channel, average handling time, FCR rate, and SLA compliance
- AND the report MUST include trend lines showing direction of change
- AND year-over-year comparison MUST be available when sufficient data exists

#### Scenario: Peak hours analysis

- GIVEN contactmomenten data for the past 4 weeks
- WHEN the manager views the peak hours analysis
- THEN the system MUST display a heatmap showing contact volume by day-of-week and hour-of-day
- AND peak hours MUST be highlighted to support staffing decisions
- AND the heatmap MUST use color intensity to represent volume (light = low, dark = high)

#### Scenario: Subject/category trend analysis

- GIVEN contactmomenten data with subject categorization for the past 3 months
- WHEN the manager views the subject trend report
- THEN the system MUST display which contact subjects are increasing or decreasing in frequency
- AND the report MUST highlight emerging topics (new subjects appearing in the last 30 days)
- AND each subject MUST show total count, trend direction, and average handling time

---

### Requirement: Export and BI Integration

The system MUST support exporting report data for use in external BI tools and management presentations.

**Feature tier**: MVP

#### Scenario: Export dashboard data as CSV

- GIVEN the KCC manager is viewing the monthly trend report
- WHEN they click "Exporteer als CSV"
- THEN the system MUST generate a CSV file with all displayed data points including headers
- AND the file MUST be immediately downloadable
- AND the CSV MUST use semicolon separators and UTF-8 with BOM for correct Dutch character display in Excel

#### Scenario: Export dashboard as PDF

- GIVEN the KCC manager is viewing the daily KPI dashboard
- WHEN they click "Exporteer als PDF"
- THEN the system MUST generate a PDF with the dashboard layout, charts, and KPI values
- AND the PDF MUST include the report date, generation timestamp, and the municipality name from Nextcloud settings
- AND charts MUST be rendered as images in the PDF using server-side rendering

#### Scenario: Scheduled report delivery

- GIVEN the KCC manager configures a weekly report to be delivered every Monday at 08:00
- WHEN Monday 08:00 arrives
- THEN the system MUST generate the report covering the previous week via a Nextcloud background job (IJob)
- AND deliver it via Nextcloud notification with a download link to the generated PDF
- AND the schedule MUST be configurable per report type (daily, weekly, monthly)
- AND the report file MUST be stored in the user's Nextcloud Files under "Rapporten/Pipelinq/"

#### Scenario: API-based data extraction for BI tools

- GIVEN an external BI tool (e.g., Power BI, Metabase) needs access to contactmoment data
- WHEN the BI tool queries the OpenRegister API with appropriate authentication
- THEN the API MUST return contactmoment data in JSON format with all properties available for analysis
- AND the API MUST support date range filtering, channel filtering, and pagination
- AND the API response MUST include aggregate endpoints for common KPI calculations

---

### Requirement: SLA Configuration

The system MUST allow administrators to configure SLA targets per channel and contact type.

**Feature tier**: MVP

#### Scenario: Configure phone SLA target

- GIVEN an administrator accessing the SLA configuration in Pipelinq settings
- WHEN they set the phone channel SLA to "90% answered within 30 seconds" and average handling time target to "5 minutes"
- THEN the system MUST store these targets via `IAppConfig` under the `pipelinq` app namespace
- AND the dashboard MUST use these targets for SLA compliance calculations
- AND changes MUST take effect immediately on the dashboard without page reload

#### Scenario: Configure per-channel targets

- GIVEN SLA targets need to differ per channel
- WHEN the administrator configures: telefoon (30s wait, 5min handle), e-mail (8h response, 24h resolution), balie (5min wait, 10min handle), chat (30s response, 10min handle)
- THEN the system MUST store separate targets per channel
- AND the dashboard MUST evaluate each channel against its own targets
- AND the configuration UI MUST validate that targets are positive numbers

#### Scenario: Configure SLA warning thresholds

- GIVEN SLA targets are configured per channel
- WHEN the administrator sets warning thresholds (e.g., "warn at 85%, critical at 80%")
- THEN the dashboard MUST use three-color coding: green (above target), orange (between warning and critical), red (below critical)
- AND the thresholds MUST be configurable independently per channel

---

### Requirement: WOO/Open Data Reporting

The system MUST support generating reports compliant with WOO (Wet open overheid) requirements for public transparency on service levels.

**Feature tier**: V1

#### Scenario: Generate WOO-compliant service report

- GIVEN contactmomenten data for a calendar quarter
- WHEN the manager generates a WOO report
- THEN the system MUST generate an anonymized report showing: total contacts per channel, average wait times, SLA compliance percentages, and first-call resolution rates
- AND the report MUST NOT contain any personally identifiable information (no agent names, no citizen data)
- AND the report MUST be exportable as PDF and CSV for publication

#### Scenario: Annual service statistics

- GIVEN contactmomenten data for a full calendar year
- WHEN the manager generates an annual statistics report
- THEN the system MUST display year-over-year comparisons on all key metrics
- AND the report MUST include monthly breakdowns and quarterly summaries
- AND the report format MUST follow VNG's recommended KCC reporting template

#### Scenario: Benchmark comparison data

- GIVEN the municipality has configured its size category (klein/middel/groot)
- WHEN the manager views benchmark indicators
- THEN the system MUST display how the municipality's KPIs compare to VNG benchmark averages for its size category
- AND benchmark data MUST be manually configurable (not automatically fetched)

---

### Requirement: Contact Moment Duration Tracking

The system MUST accurately track the duration of each contact moment to enable meaningful handling time analytics.

**Feature tier**: MVP

#### Scenario: Auto-timer for phone contacts

- GIVEN an agent starts registering a phone contact moment
- WHEN the contact moment form opens
- THEN the system MUST automatically start a duration timer
- AND the timer MUST be visible to the agent
- AND when the agent completes the registration, the elapsed time MUST be stored as the handling duration

#### Scenario: Manual duration entry for email contacts

- GIVEN an agent registers an email contact moment
- WHEN the agent fills in the registration form
- THEN the system MUST allow manual entry of the handling duration in minutes
- AND the field MUST default to empty (not auto-timed, since email handling is non-continuous)

#### Scenario: Duration correction

- GIVEN an agent completed a phone contact but forgot to stop the timer before taking a break
- WHEN the agent edits the contact moment within 24 hours
- THEN the system MUST allow correcting the duration
- AND the correction MUST be logged in the audit trail with the original and corrected values

---

### Requirement: Contact Moment Categorization

The system MUST support categorizing contact moments by subject to enable topic-based analytics.

**Feature tier**: MVP

#### Scenario: Select primary subject during registration

- GIVEN an agent is registering a contact moment
- WHEN the agent reaches the subject field
- THEN the system MUST display a configurable list of subject categories (e.g., "Burgerzaken", "Belastingen", "Vergunningen", "Afval", "Overig")
- AND the categories MUST be managed via SystemTags (reusing the existing `SystemTagService`)
- AND the agent MUST be able to select one primary category

#### Scenario: Subject analytics in dashboard

- GIVEN contactmomenten data categorized by subject for the past month
- WHEN the manager views the subject analytics
- THEN the system MUST display a breakdown of contacts per subject category
- AND each category MUST show: count, percentage of total, average handling time, and FCR rate
- AND the categories MUST be sortable by any metric

#### Scenario: Subject trend alerts

- GIVEN a subject category "Afval" normally receives 50 contacts per week
- AND this week the count has reached 120 contacts (140% increase)
- WHEN the system detects this anomaly
- THEN the system MUST flag the category with a "Trending" indicator on the dashboard
- AND a notification MUST be sent to configured managers about the spike

---

## Appendix

### Current Implementation Status

**NOT implemented.** No contactmomenten schema, KPI dashboard, reporting, or SLA monitoring exists in the codebase.

- No `contactmoment` schema in `lib/Settings/pipelinq_register.json`. The register defines: client, contact, lead, request, pipeline, product, productCategory, leadProduct -- but no contactmoment entity.
- No reporting controllers, services, or dashboard components for contact moment analytics.
- No SLA configuration storage or monitoring.
- No agent performance tracking.
- No channel analytics or wait time monitoring.
- No CSV/PDF export for reports.
- No scheduled report delivery mechanism.
- The existing `src/views/Dashboard.vue` shows CRM KPIs (open leads, open requests, pipeline value, overdue items) via `CnStatsBlock` widgets but no contactmoment-based metrics.
- The existing `request` entity has a `channel` property which could map to contactmoment channels, but the request entity is not the same as a contactmoment.
- `MetricsRepository` and `MetricsFormatter` services exist for basic CRM metrics but do not cover contactmoment analytics.
- `SystemTagService` and `SystemTagCrudService` exist for tag-based categorization and could be reused for subject categories.

### Competitor Comparison

- **EspoCRM**: Paid Advanced Pack ($395/year) offers a flexible report builder with any-entity reports, grid reports, CSV/Excel export, and email-scheduled delivery. Free tier has basic pipeline charts only.
- **Twenty**: Dashboard with 6 widget types (bar, pie, line, aggregate, iframe, rich text) but still in beta with no table widgets or export. No KCC-specific reporting.
- **Krayin**: Basic dashboard analytics for leads/activities but no dedicated contact moment reporting or SLA monitoring.
- **Pipelinq advantage**: OpenRegister's faceted aggregation provides real-time data without a separate analytics engine. Nextcloud's background job system (`IJob`) enables scheduled report generation natively.

### Standards & References
- VNG Klantinteracties API -- defines `Contactmoment` entity with properties: kanaal, tekst, onderwerpLinks, initiatiefnemer, registratiedatum, medewerker, klant
- Common Ground -- API-based data extraction for reporting
- KCS (Knowledge-Centered Service) -- methodology for first-call resolution tracking
- ISO 18295 -- Customer contact centres, requirements for service provision
- WCAG AA -- for dashboard accessibility
- WOO (Wet open overheid) -- public transparency requirements for government service levels
- Dutch government SLA standards for KCC operations (typically 80% calls answered within 30 seconds)
- VNG benchmark rapportage -- standard KCC reporting template for municipalities

### Specificity Assessment
- The spec is well-structured with clear KPI definitions and calculation formulas.
- **Implementable as-is** for the dashboard and basic KPIs, but depends on the contactmoment entity being implemented first (see kcc-werkplek spec).
- **Missing**: No specification of the contactmoment schema (this is defined in the kcc-werkplek spec, creating a dependency).
- **Missing**: No specification of how "real-time" data is delivered (polling at 30-60 second intervals via OpenRegister API is the assumed approach).
- **Missing**: No specification of data retention -- how long should contactmoment data be kept for trend analysis? Recommendation: configurable, default 2 years.
- **Resolved**: "Average handling time" uses auto-timer for phone/balie and manual entry for email/chat (see Duration Tracking requirement).
- **Open question**: Should reporting use OpenRegister's aggregation API or a dedicated analytics service? Recommendation: OpenRegister faceted queries for real-time, cached snapshots for historical trends.
- **Open question**: How does the "queue length" KPI work? Pipelinq does not manage telephony queues. Recommendation: integrate via OpenConnector source to external PBX (Asterisk, 3CX, etc.).
