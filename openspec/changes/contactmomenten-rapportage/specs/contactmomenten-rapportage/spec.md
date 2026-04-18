# Contactmomenten Rapportage Specification (Cross-App)

## Purpose

Contactmomenten rapportage provides management dashboards, KPI monitoring, and reporting on all registered contact moments across apps. This enables KCC managers, case managers, and organizational leaders to monitor service levels, identify bottlenecks, and optimize staffing. This is a top-demanded capability: **98% of klantinteractie-tenders** (51/52) require contact moment reporting with KPIs and SLA monitoring, and **88% of all tenders** (65/73) require rapportage/dashboards.

The reporting capability is cross-app: Pipelinq generates contactmoment data from KCC interactions, Procest tracks case-related contact events, and both feed into shared reporting dashboards. OpenRegister provides the data aggregation layer.

**Consuming apps**: Pipelinq (primary KCC reporting), Procest (case contact reporting), OpenRegister (data aggregation)
**Tender frequency**: 51/52 KCC tenders (98%); 65/73 all tenders (88%)
**Standards**: VNG Klantinteracties, ISO 18295, Common Ground, WOO

---

## Requirements

### Requirement 1: KPI Dashboard with real-time metrics

The system MUST provide a real-time dashboard showing key performance indicators.

#### Scenario 1.1: Display daily KPI overview
- GIVEN 150 contactmomenten registered today across all agents
- WHEN a KCC manager opens the rapportage dashboard
- THEN the system MUST display: total contacts today (150), contacts per channel (telefoon/email/balie/chat), average handling time, current queue length, agents currently active
- AND each KPI MUST show a trend indicator compared to the same day last week
- AND KPI widgets MUST use shared component library for consistent display

#### Scenario 1.2: Display first-call resolution rate
- GIVEN 150 contactmomenten today, of which 112 were resolved without routing to backoffice
- WHEN the manager views the dashboard
- THEN FCR MUST be displayed as 74.7%, compared against the configured target (e.g., 80%)
- AND if below target, the KPI MUST be highlighted in orange/red

#### Scenario 1.3: Display SLA compliance
- GIVEN an SLA target "90% of phone calls answered within 30 seconds"
- AND 95 phone calls today, of which 80 answered within 30 seconds
- THEN SLA compliance MUST be displayed as 84.2% with visual color coding (green >= target, orange within 5%, red > 5% below)

#### Scenario 1.4: Dashboard auto-refresh
- GIVEN the dashboard is open
- WHEN 60 seconds have elapsed
- THEN the dashboard MUST automatically fetch updated data without flickering or layout shifts
- AND a "Last updated" timestamp MUST be displayed

#### Scenario 1.5: Dashboard empty state
- GIVEN no contactmomenten registered today
- THEN all KPI widgets MUST display "0" or "N/A"
- AND a message "Nog geen contactmomenten geregistreerd vandaag" MUST be shown

---

### Requirement 2: Channel analytics

The system MUST provide detailed analytics per contact channel.

#### Scenario 2.1: Channel distribution chart
- GIVEN contactmomenten data for the past 30 days
- THEN the system MUST display a chart showing contact volume per channel over time
- AND the chart MUST support daily, weekly, and monthly granularity
- AND each channel MUST be color-coded consistently

#### Scenario 2.2: Channel comparison table
- GIVEN contactmomenten data for the current month
- THEN the system MUST display per channel: total contacts, average handling time, FCR rate, SLA compliance
- AND each metric MUST show a comparison indicator versus the previous month

#### Scenario 2.3: Channel shift analysis
- GIVEN contactmomenten data spanning 12 months
- THEN the system MUST display how channel distribution has changed over time
- AND channels with statistically significant shifts (>5% change) MUST be highlighted

---

### Requirement 3: Wait time monitoring

The system MUST track and report on wait times for incoming contacts.

#### Scenario 3.1: Real-time queue statistics
- GIVEN 5 contacts waiting in the phone queue
- THEN the system MUST display: number waiting, longest wait time, average wait time, estimated wait for new callers
- AND the display MUST update at configurable intervals (default: 30 seconds)

#### Scenario 3.2: Historical wait time report
- GIVEN wait time data for the past week
- THEN the system MUST display: average wait time per day, peak hours, percentage within SLA
- AND staffing recommendations MUST be provided based on identified patterns

#### Scenario 3.3: Wait time SLA breach alert
- GIVEN real-time SLA compliance drops below 80%
- THEN a prominent warning MUST be displayed on the dashboard
- AND configured managers MUST receive a Nextcloud notification

---

### Requirement 4: Agent performance metrics

The system MUST provide per-agent statistics for team management.

#### Scenario 4.1: Individual agent statistics
- GIVEN agent "Medewerker A" has handled 25 contacts today
- THEN the system MUST display: contacts handled, average handling time, FCR rate, contacts per hour
- AND metrics MUST be comparable to team averages

#### Scenario 4.2: Team overview
- GIVEN a KCC team of 10 agents
- THEN a ranked list MUST display: name, contacts today, avg handling time, FCR rate, current status
- AND agents significantly above or below averages MUST be highlighted

#### Scenario 4.3: Agent workload distribution
- GIVEN varying contact loads across agents
- THEN a bar chart MUST show contacts per agent
- AND agents with >20% above average workload MUST be flagged

#### Scenario 4.4: Agent performance over time
- GIVEN 30 days of agent data
- THEN a line chart MUST show daily contacts, FCR rate, and handling time trends
- AND improvement or decline MUST be indicated

---

### Requirement 5: Trend reporting

The system MUST provide trend reports over configurable time periods.

#### Scenario 5.1: Monthly trend report
- GIVEN 6 months of contactmomenten data
- THEN per month: total contacts, per channel, avg handling time, FCR rate, SLA compliance MUST be displayed
- AND trend lines MUST show direction of change

#### Scenario 5.2: Peak hours heatmap
- GIVEN 4 weeks of data
- THEN a heatmap MUST show contact volume by day-of-week and hour-of-day
- AND peak hours MUST be highlighted for staffing decisions

#### Scenario 5.3: Subject/category trend analysis
- GIVEN 3 months of categorized data
- THEN subject frequency changes MUST be displayed
- AND emerging topics (new subjects in last 30 days) MUST be highlighted

---

### Requirement 6: Export and BI integration

The system MUST support exporting report data for external BI tools.

#### Scenario 6.1: Export as CSV
- GIVEN any report view
- WHEN the user clicks "Exporteer als CSV"
- THEN a CSV with all data points, semicolon separators, UTF-8 BOM MUST be downloaded

#### Scenario 6.2: Export as PDF
- GIVEN the daily KPI dashboard
- WHEN the user clicks "Exporteer als PDF"
- THEN a PDF with dashboard layout, charts, and KPI values MUST be generated
- AND the PDF MUST include report date, timestamp, and municipality name

#### Scenario 6.3: Scheduled report delivery
- GIVEN a configured weekly report for every Monday at 08:00
- THEN the system MUST generate the report via a background job
- AND deliver via Nextcloud notification with download link
- AND store the file in the user's Nextcloud Files under "Rapporten/"

#### Scenario 6.4: API-based data extraction
- GIVEN an external BI tool needs data
- THEN the API MUST return contactmoment data in JSON with date range filtering, channel filtering, and pagination
- AND aggregate endpoints for common KPI calculations MUST be available

---

### Requirement 7: SLA configuration

Administrators MUST be able to configure SLA targets per channel and contact type.

#### Scenario 7.1: Configure phone SLA target
- GIVEN admin SLA configuration
- WHEN they set phone SLA to "90% answered within 30 seconds"
- THEN the dashboard MUST use this target for calculations
- AND changes MUST take effect immediately

#### Scenario 7.2: Per-channel targets
- GIVEN different channels need different SLAs
- THEN separate targets per channel MUST be configurable: telefoon (30s wait), email (8h response), balie (5min wait), chat (30s response)

#### Scenario 7.3: Warning thresholds
- GIVEN SLA targets per channel
- THEN configurable warning thresholds (e.g., warn at 85%, critical at 80%) MUST support three-color coding: green, orange, red

---

### Requirement 8: WOO/Open Data reporting

The system MUST support generating WOO-compliant reports for public transparency.

#### Scenario 8.1: Generate WOO-compliant service report
- GIVEN a calendar quarter of data
- THEN an anonymized report with total contacts per channel, average wait times, SLA compliance, FCR rates MUST be generated
- AND NO personally identifiable information MUST be included

#### Scenario 8.2: Annual service statistics
- GIVEN a full calendar year of data
- THEN year-over-year comparisons on all key metrics MUST be available
- AND the report format MUST follow VNG's recommended KCC reporting template

#### Scenario 8.3: Benchmark comparison
- GIVEN the municipality's size category (klein/middel/groot)
- THEN KPIs compared to VNG benchmark averages MUST be displayable
- AND benchmark data MUST be manually configurable

---

### Requirement 9: Contact moment duration tracking

The system MUST accurately track duration for meaningful handling time analytics.

#### Scenario 9.1: Auto-timer for phone contacts
- GIVEN an agent starts registering a phone contact
- THEN a duration timer MUST automatically start and be visible
- AND elapsed time MUST be stored as handling duration on completion

#### Scenario 9.2: Manual duration for email
- GIVEN an email contact registration
- THEN manual duration entry in minutes MUST be allowed
- AND the field MUST default to empty

#### Scenario 9.3: Duration correction
- GIVEN an agent needs to correct a timer value
- THEN correction MUST be allowed within 24 hours
- AND correction MUST be logged in the audit trail

---

### Requirement 10: Contact moment categorization

The system MUST support categorizing contact moments by subject for topic-based analytics.

#### Scenario 10.1: Select primary subject during registration
- GIVEN a configurable list of subject categories (Burgerzaken, Belastingen, Vergunningen, Afval, etc.)
- THEN the agent MUST select one primary category during registration
- AND categories MUST be manageable via the app's tag system

#### Scenario 10.2: Subject analytics in dashboard
- GIVEN categorized data for the past month
- THEN per category: count, percentage, average handling time, FCR rate MUST be displayed

#### Scenario 10.3: Subject trend alerts
- GIVEN a category normally receives 50 contacts per week but this week has 120
- THEN a "Trending" indicator MUST be flagged on the dashboard
- AND configured managers MUST be notified about the spike

---

### Requirement 11: Cross-app reporting aggregation

Reports MUST aggregate data from multiple apps when available.

#### Scenario 11.1: Pipelinq + Procest combined contact report
- GIVEN Pipelinq tracks KCC contactmomenten and Procest tracks case-related contacts
- WHEN a manager views the combined report
- THEN data from both apps MUST be aggregated with source app indicated
- AND filtering by app MUST be supported

#### Scenario 11.2: Cross-app KPI consistency
- GIVEN the same contact is registered in both Pipelinq (as contactmoment) and Procest (as case event)
- THEN the reporting system MUST deduplicate based on linked entity references
- AND the total count MUST not double-count linked contacts

#### Scenario 11.3: Unified channel statistics
- GIVEN channels are used across both Pipelinq and Procest
- THEN channel distribution charts MUST aggregate from all sources
- AND per-app breakdown MUST be available as a drill-down

---

### Requirement 12: Data retention and archival

Contactmoment data MUST support configurable retention for long-term trend analysis.

#### Scenario 12.1: Configurable retention period
- GIVEN an admin configuration for data retention
- THEN the default retention MUST be 2 years
- AND the admin MUST be able to set a custom retention period (1-10 years)

#### Scenario 12.2: Archived data for trend analysis
- GIVEN data older than the retention period
- THEN detailed records MAY be deleted
- BUT aggregated statistics (monthly totals, averages) MUST be preserved indefinitely for trend analysis

#### Scenario 12.3: GDPR-compliant data deletion
- GIVEN a citizen requests data deletion under AVG
- THEN their contactmoment records MUST be anonymized (agent and content removed)
- BUT aggregated statistical data MUST be preserved (it is no longer personal data)

---

## Data Model

Reporting is built on aggregated contactmoment data from OpenRegister:
- **Contactmoment**: Source entity with channel, duration, timestamp, agent, outcome, linked client/zaak
- **KPI calculations**: Derived from contactmoment properties (averages, counts, percentages)
- **SLA targets**: Configurable thresholds stored as application settings
- **Report snapshots**: Cached aggregations for historical trend analysis

---

## Dependencies

- OpenRegister (contactmoment storage and aggregation API)
- Pipelinq (KCC contactmoment registration)
- Procest (case contact events)
- Nextcloud background jobs (`ITimedJob`) for scheduled reports
- Nextcloud Files for report storage
- Nextcloud Notification API for alerts

## Standards & References

- VNG Klantinteracties API -- `Contactmoment` entity
- ISO 18295 -- Customer contact centres service requirements
- WOO (Wet open overheid) -- public transparency requirements
- KCS (Knowledge-Centered Service) -- FCR tracking methodology
- WCAG AA -- dashboard accessibility
- VNG benchmark rapportage -- standard KCC reporting template
- Common Ground -- API-based data extraction
