# Spec: Klantbeeld 360

## Overview

Klantbeeld 360 delivers three capabilities for the Pipelinq CRM:
1. **Client 360° view** — unified client detail page aggregating leads, contactmomenten, requests,
   and contacts
2. **Sales pipeline analytics** — per-pipeline KPI dashboard and stage funnel
3. **Contact–organisation management** — bidirectional navigation and quick-link UX

Entities used (all defined in ADR-000): `client`, `contact`, `lead`, `contactmoment`, `request`,
`pipeline`. No new schemas are introduced.

---

## Feature 1: Client 360° View

### REQ-KB360-001 — Summary Statistics Card

The client detail page MUST display a summary statistics card aggregating key metrics from
linked entities.

#### Scenario: Display summary statistics for a client with linked data

GIVEN a client detail page is open
AND the client has associated leads and requests
WHEN the page finishes loading
THEN a summary statistics card MUST appear with four metrics:
  - Open leads: count and total EUR value
  - Won leads: count and total EUR value
  - Open requests: count
  - Contactmomenten: total count
AND monetary values MUST be formatted with EUR currency (e.g., "€ 42.000")
AND counts MUST reflect only entities where `lead.client` / `request.client` / `contactmoment.client`
  matches this client's UUID

#### Scenario: Summary statistics with no linked data

GIVEN a newly created client with no linked leads, requests, or contactmomenten
WHEN the client detail page loads
THEN all statistics MUST display `0` (not blank, not an error message)

---

### REQ-KB360-002 — Leads Section on Client Detail

The client detail page MUST include a "Leads" section listing the most recent associated leads.

#### Scenario: View leads linked to a client

GIVEN a client with one or more leads where `lead.client` equals this client's UUID
WHEN the client detail page loads
THEN a "Leads" `CnDetailCard` section MUST be displayed
AND the section MUST show up to 10 leads sorted by `updatedAt` descending
AND each lead row MUST show: title, stage name, value (EUR), probability (%), expected close date
AND clicking a lead row MUST navigate to `/leads/{lead.uuid}`

#### Scenario: Empty leads section

GIVEN a client with no associated leads
WHEN the client detail page loads
THEN the Leads section MUST display an empty state (no error; "No leads linked" or equivalent)

---

### REQ-KB360-003 — Contactmomenten Section on Client Detail

The client detail page MUST include a "Contactmomenten" section listing the most recent
interactions with this client.

#### Scenario: View contactmomenten for a client

GIVEN a client with one or more contactmomenten where `contactmoment.client` equals this client's UUID
WHEN the client detail page loads
THEN a "Contactmomenten" `CnDetailCard` section MUST be displayed
AND the section MUST show up to 10 entries sorted by `contactedAt` descending
AND each row MUST show: subject, channel, contactedAt (formatted date), agent UID, outcome

#### Scenario: Empty contactmomenten section

GIVEN a client with no contactmomenten
WHEN the client detail page loads
THEN the Contactmomenten section MUST display an empty state

---

### REQ-KB360-004 — Requests Section on Client Detail

The client detail page MUST include a "Requests" section listing the most recent service requests.

#### Scenario: View requests linked to a client

GIVEN a client with one or more requests where `request.client` equals this client's UUID
WHEN the client detail page loads
THEN a "Requests" `CnDetailCard` section MUST be displayed
AND the section MUST show up to 5 requests sorted by `requestedAt` descending
AND each row MUST show: title, status, priority, requestedAt (formatted date)

---

### REQ-KB360-005 — Contacts Section on Client Detail

The client detail page MUST list all associated contact persons.

#### Scenario: Display linked contacts on client detail

GIVEN a client organisation with associated contacts where `contact.client` equals this client's UUID
WHEN the client detail page loads
THEN a "Contacts" `CnDetailCard` section MUST list all associated contact persons
AND each contact row MUST show: name, role, email, phone
AND clicking a contact row MUST navigate to `/contacts/{contact.uuid}`

#### Scenario: Contact section for a client with no contacts

GIVEN a client with no associated contact persons
WHEN the client detail page loads
THEN the Contacts section MUST display an empty state with an "Add Contact" action

---

### REQ-KB360-006 — Parallel Loading with Section-Level Loading States

GIVEN a client detail page is opened
WHEN the page is fetching relation data (leads, contactmomenten, requests, contacts)
THEN each section MUST show an individual loading indicator until its fetch resolves
AND a failure in one section's fetch MUST NOT prevent other sections from rendering
AND failed sections MUST display an error message (not a blank section)

---

## Feature 2: Sales Pipeline Analytics

### REQ-KB360-010 — Pipeline Analytics View Access

A dedicated pipeline analytics view MUST be accessible from the main navigation.

#### Scenario: Navigate to pipeline analytics

GIVEN a user is authenticated and navigates to `/pipeline-analytics`
WHEN the page loads
THEN a pipeline selector dropdown MUST be displayed listing all available pipelines
AND the first pipeline MUST be auto-selected if a default pipeline exists (`pipeline.isDefault = true`)

---

### REQ-KB360-011 — Pipeline KPI Cards

The pipeline analytics view MUST display four KPI cards for the selected pipeline.

#### Scenario: KPI cards for a pipeline with active leads

GIVEN a pipeline is selected that has leads in multiple stages
WHEN the analytics view has fetched leads for that pipeline
THEN four KPI cards MUST be displayed using `CnStatsBlock`:
  1. **Total Pipeline Value** — sum of `lead.value` for leads with `status = 'active'`
  2. **Win Rate** — `won / (won + lost)` as a percentage; shown as `—` when no closed leads exist
  3. **Average Deal Size** — total open value / count of active leads; shown as `—` when no active leads
  4. **Active Opportunities** — count of leads with `status = 'active'`
AND monetary values MUST be formatted as EUR currency

#### Scenario: KPI cards for a pipeline with no leads

GIVEN a pipeline with no associated leads
WHEN the analytics view loads
THEN all four KPI cards MUST display `0` or `—` (no errors, no blank cards)

---

### REQ-KB360-012 — Stage Funnel Chart

The pipeline analytics view MUST display a funnel chart showing lead distribution across stages.

#### Scenario: Stage funnel visualization

GIVEN a pipeline is selected with leads distributed across multiple stages
WHEN the analytics view loads
THEN a `CnChartWidget` bar chart MUST display the count of leads per stage
AND stages MUST be ordered by `stageOrder` (ascending)
AND the chart MUST use horizontal bar orientation
AND the chart MUST be keyboard-accessible (WCAG AA)

#### Scenario: Stage funnel with single-stage or empty pipeline

GIVEN a pipeline where all leads are in a single stage
WHEN the analytics view loads
THEN the chart MUST still render (showing one bar) without errors

---

### REQ-KB360-013 — Pipeline Selector Reactivity

GIVEN the pipeline analytics view is open
WHEN the user selects a different pipeline from the dropdown
THEN the KPI cards and stage funnel chart MUST update immediately to reflect the new pipeline's data
AND a loading state MUST be shown during the fetch

---

## Feature 2b: Opportunity Tracking in Lead List

### REQ-KB360-014 — Expected Close Date Warning

GIVEN a lead with `expectedCloseDate` set
WHEN the lead appears in the lead list (`LeadList.vue`)
AND the expected close date is within the next 7 days (inclusive of today)
THEN the expected close date cell MUST display a warning icon alongside the date
AND the warning MUST NOT rely on color alone (icon MUST be present — WCAG AA)
AND leads with `expectedCloseDate` in the past MUST display an overdue indicator (icon + different color)

---

### REQ-KB360-015 — Probability Badge in Lead List

GIVEN a lead with a `probability` value
WHEN the lead appears in the lead list
THEN the probability MUST be displayed as a percentage value
AND leads with `probability < 30` MUST display a low-probability badge
AND the badge MUST meet WCAG AA (not color-only — include a label or icon)

---

## Feature 3: Cross-module Analytics Dashboard

### REQ-KB360-020 — Analytics Dashboard KPI Cards

A cross-module analytics dashboard MUST be available at `/analytics`.

#### Scenario: Dashboard KPI cards on load

GIVEN a user navigates to `/analytics`
WHEN the dashboard loads
THEN four KPI cards MUST be displayed using `CnDashboardPage` with `CnStatsBlock`:
  1. **Open Pipeline Value** — total EUR value of active leads across all pipelines
  2. **Open Requests** — count of requests with `status` not in `{closed, rejected}`
  3. **Contactmomenten** — count of contactmomenten in the selected time period
  4. **Active Leads** — count of leads with `status = 'active'`
AND data MUST be fetched from `GET /api/analytics/summary?period={period}`

#### Scenario: Dashboard KPI cards with no data

GIVEN no leads, requests, or contactmomenten exist
WHEN the analytics dashboard loads
THEN all KPI cards MUST display `0` (not blank, not an error)

---

### REQ-KB360-021 — Time Period Filter

GIVEN the analytics dashboard is displayed
WHEN the user selects a time period from the header filter (this week / this month / this quarter)
THEN the "Contactmomenten" KPI count MUST update to reflect the selected period boundary
AND the filter selection MUST persist during the browser session (page reload resets to default: month)
AND the filter control MUST be placed in the dashboard page header using the `header-actions` slot
  (per ADR-018)

---

### REQ-KB360-022 — Analytics Dashboard Data Freshness

GIVEN the analytics dashboard is displayed
WHEN the user navigates away and returns to `/analytics`
THEN the dashboard MUST re-fetch summary data on mount
AND a loading state MUST be shown while the fetch is in progress

---

## Feature 3b: Contact–Organisation Management

### REQ-KB360-030 — Parent Organisation Card on Contact Detail

GIVEN a contact person with `contact.client` set to a valid client UUID
WHEN the contact detail page (`ContactDetail.vue`) is opened
THEN a "Parent Organisation" `CnDetailCard` MUST display:
  - The linked client's `name`
  - The linked client's `type` (person / organization)
AND clicking the client name MUST navigate to `/clients/{client.uuid}`

---

### REQ-KB360-031 — Contact Without Parent Organisation

GIVEN a contact person where `contact.client` is null or not set
WHEN the contact detail page is opened
THEN the "Parent Organisation" card MUST display an empty state message
AND a "Link to Organisation" button MUST be visible
AND clicking the button MUST open an organisation selection dialog (using `CnFormDialog`)
  with a searchable client list

---

### REQ-KB360-032 — Link Contact to Organisation

GIVEN the organisation selection dialog is open
WHEN the user selects a client and confirms
THEN `contact.client` MUST be set to the selected client's UUID
AND the contact MUST be saved via `objectStore.saveObject`
AND on save success, the Parent Organisation card MUST immediately display the linked client
AND on save failure, a user-facing error notification MUST be shown (never silently fail)
AND the save MUST be wrapped in `try/catch` (per ADR-004)

---

## Dutch API Mapping

Per ADR-001 (International First, Dutch Mapping Layer), the following primary schema.org / vCard
properties are used. Dutch equivalents are shown for reference only — they are NOT stored:

| Property (stored) | schema.org / vCard | Dutch equivalent |
|-------------------|--------------------|-----------------|
| `contact.name` | vCard FN | naam |
| `contact.email` | vCard EMAIL | emailadres |
| `contact.phone` | vCard TEL | telefoonnummer |
| `client.name` | schema:name | naam |
| `lead.value` | schema:price | opdrachtwaarde |
| `lead.probability` | schema:offerCount (custom) | kans |
| `contactmoment.contactedAt` | schema:startTime | registratiedatum |
| `contactmoment.channel` | schema:instrument | kanaal |
| `contactmoment.outcome` | schema:result | resultaat |

---

## Non-functional Requirements

### REQ-KB360-090 — Accessibility (WCAG AA)

GIVEN any new or modified view in this change
THEN all visual indicators (warnings, badges, status) MUST NOT rely on color alone
AND all interactive elements MUST be keyboard-navigable with a visible focus ring
AND all form inputs MUST have associated labels

### REQ-KB360-091 — Responsiveness

GIVEN the analytics dashboard or pipeline analytics view is displayed on a 768px-wide viewport
THEN the KPI card grid MUST collapse to at most 2 columns
AND core KPI data MUST remain visible and usable without horizontal scrolling

### REQ-KB360-092 — Translation

GIVEN any new or modified view in this change
THEN all user-visible strings MUST use `this.t(appName, 'English key')` in Vue (never hardcoded)
AND Dutch translations MUST be added to `l10n/nl.json` for every new English key
AND no Dutch strings MUST appear as `t()` keys
