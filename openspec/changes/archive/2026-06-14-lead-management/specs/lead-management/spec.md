<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Lead Management — Delta Spec

## Purpose

This delta spec adds V1 pipeline efficiency features, contract portfolio analytics and reporting, and non-admin pipeline access verification to the existing `specs/lead-management/spec.md`.

**Main spec ref**: [lead-management/spec.md](../../../../specs/lead-management/spec.md)
**Feature tiers**: V1 (pipeline enhancements, analytics), MVP (non-admin access)

---

## Requirements

### REQ-LM-001: Quick Actions on Kanban Cards [V1]

The system MUST provide a quick-action menu on lead kanban cards so users can perform common operations without navigating to the lead detail view.

#### Scenario 1: Move lead to another stage from card menu

- GIVEN a lead card "Gemeente Amsterdam — CRM implementatie 2026" in stage "Gekwalificeerd" on the pipeline board
- WHEN the user opens the card action menu and selects "Verplaats naar stage" → "Onderhandeling"
- THEN the lead's `stage` MUST be updated to "Onderhandeling" and `stageOrder` updated accordingly via `objectStore.saveObject`
- AND the card MUST visually move to the "Onderhandeling" column
- AND a success notification MUST display: "Lead verplaatst naar Onderhandeling"
- AND if the stage move fails (e.g. network error), an error notification MUST display and the card MUST remain in its original column

#### Scenario 2: Assign lead to a user from card menu

- GIVEN an unassigned lead card on the pipeline board
- WHEN the user opens the card action menu and selects "Toewijzen"
- THEN a user picker MUST appear showing available Nextcloud users
- AND selecting user "jan" MUST update `assignee: "jan"` on the lead via `objectStore.saveObject`
- AND the card MUST update to show the assigned user's avatar

#### Scenario 3: Change lead priority from card menu

- GIVEN a lead card with `priority: "normal"`
- WHEN the user opens the card action menu and selects "Prioriteit" → "urgent"
- THEN the lead's `priority` MUST be updated to "urgent" via `objectStore.saveObject`
- AND the card MUST immediately display the urgent priority badge
- AND selecting "normal" MUST remove the priority badge (normal is the baseline, no badge shown)

---

### REQ-LM-002: Stale Lead Detection [V1]

The system MUST detect leads with no activity for a configurable number of days and display a stale badge to prevent forgotten opportunities.

#### Scenario 4: Stale badge on kanban card

- GIVEN a lead with `_dateModified` 18 days ago
- AND the stale threshold is configured to 14 days (default)
- WHEN the lead appears on the pipeline board kanban
- THEN a stale badge MUST be displayed on the card: "18d oud"
- AND the badge MUST use a warning color (amber/orange) distinct from the overdue indicator

#### Scenario 5: No stale badge for recently active leads

- GIVEN a lead with `_dateModified` 5 days ago and stale threshold of 14 days
- WHEN the lead appears on the pipeline board
- THEN no stale badge MUST be shown on the card

#### Scenario 6: Stale filter in lead list

- GIVEN 10 leads of which 3 have `_dateModified` older than the stale threshold
- WHEN the user applies the "Verouderd" filter in the lead list view
- THEN exactly 3 leads MUST be shown
- AND the filter MUST be clearable with a single click

#### Scenario 7: Configurable stale threshold in admin settings

- GIVEN the admin navigates to Pipelinq admin settings
- WHEN they change the "Verouderd na" value to 21 days and save
- THEN the threshold MUST be persisted via `IAppConfig` with key `lead_stale_threshold_days`
- AND leads with `_dateModified` ≥ 21 days ago MUST now be flagged as stale
- AND a non-admin user MUST see the updated threshold reflected immediately

---

### REQ-LM-003: Lead Aging Indicator [V1]

The system MUST display how long a lead has been in its current stage on kanban cards and in the lead detail view.

#### Scenario 8: Aging indicator on kanban card

- GIVEN a lead with `_dateModified` 12 days ago currently in stage "Voorstel"
- WHEN the lead appears on the pipeline kanban board
- THEN the card MUST display "12d in fase" below the title
- AND the aging indicator MUST use neutral grey styling, not warning colors

#### Scenario 9: Aging display in lead detail pipeline progress section

- GIVEN a lead detail view for a lead with `_dateModified` 8 days ago in stage "Onderhandeling"
- WHEN the user views the pipeline progress section
- THEN the current stage MUST show the text "8 dagen in huidige fase"

#### Scenario 10: Aging indicator resets after stage change

- GIVEN a lead card showing "15d in fase" in stage "Voorstel"
- WHEN the user moves the lead to "Onderhandeling" via quick action or the detail view
- THEN the aging indicator MUST reset to "0d in fase" immediately after save
- AND the reset MUST occur because the stage change updates `_dateModified`

---

### REQ-LM-004: Overdue Lead Indicators [V1]

The system MUST highlight leads that have passed their `expectedCloseDate` and are still open in all relevant views.

#### Scenario 11: Overdue row highlighting in lead list

- GIVEN a lead "Rijkswaterstaat — Onderhoudscontract" with `expectedCloseDate` 5 days ago and `status: "open"`
- WHEN the lead appears in the lead list table
- THEN the row MUST have a visual overdue indicator (e.g. red left border or icon)
- AND the expected close date cell MUST display "5d te laat" in red
- AND closed leads (status `won` or `lost`) MUST NOT show the overdue indicator

#### Scenario 12: Overdue banner on lead detail view

- GIVEN a lead detail view for a lead with `expectedCloseDate` 10 days in the past and `status: "open"`
- WHEN the user opens the lead detail
- THEN a banner MUST be displayed below the page header: "10 dagen achterstallig"
- AND the banner MUST use the error/warning color (`--color-error` or NL Design token)
- AND the banner MUST NOT appear for closed leads

#### Scenario 13: Overdue indicator on pipeline kanban card

- GIVEN a lead card on the kanban board with `expectedCloseDate` yesterday and `status: "open"`
- WHEN the card is rendered
- THEN the `expectedCloseDate` text MUST be styled in red
- AND a small overdue icon MUST be visible next to the date
- AND closed leads in Won/Lost columns MUST NOT show the overdue indicator

---

### REQ-LM-005: Lead CSV Import/Export [V1]

The system MUST support exporting the lead list to CSV and importing leads from a CSV file.

#### Scenario 14: Export current lead list to CSV

- GIVEN the lead list view (possibly filtered to 15 leads)
- WHEN the user clicks "Exporteren" in the action bar and selects "CSV"
- THEN a `CnMassExportDialog` MUST open with column selection
- AND available columns MUST include at minimum: title, value, stage, source, priority, assignee, expectedCloseDate
- AND upon confirming, a CSV file named `leads-export-{date}.csv` MUST be downloaded
- AND the file MUST contain only the selected columns for each lead in the current view

#### Scenario 15: Import leads from CSV

- GIVEN a valid CSV file with columns: title, value, source, priority, expectedCloseDate
- WHEN the user clicks "Importeren" in the action bar and uploads the file via `CnMassImportDialog`
- THEN valid rows MUST create lead objects via `objectStore.saveObject('lead', data)`
- AND leads without an explicit pipeline reference MUST be assigned to the default pipeline's first non-closed stage
- AND a summary MUST display: "X leads geïmporteerd. Y rijen overgeslagen."

#### Scenario 16: Import skips rows with missing required fields

- GIVEN a CSV where row 4 has an empty title column
- WHEN the import runs
- THEN row 4 MUST be skipped
- AND the import summary MUST list: "Rij 4: Titel is verplicht"
- AND all other valid rows MUST still be imported successfully

---

### REQ-LM-006: Pipeline Analytics — Stage Value Summary [V1]

The system MUST provide a dedicated analytics view showing total and weighted lead value distributed across pipeline stages.

#### Scenario 17: View pipeline funnel chart

- GIVEN leads distributed across stages: Nieuw (3 leads, EUR 48K total), Gekwalificeerd (4 leads, EUR 110K), Voorstel (2 leads, EUR 85K)
- WHEN the user navigates to the "Rapportage" section
- THEN a pipeline funnel chart (`CnChartWidget`, bar type) MUST show each stage on the x-axis
- AND two bar series MUST be visible: "Totale waarde" and "Gewogen waarde" (value × probability)
- AND lead count per stage MUST be shown in the tooltip

#### Scenario 18: Filter analytics by pipeline

- GIVEN two pipelines: "Sales Pipeline" and "Service Pipeline" each with leads
- WHEN the user selects "Sales Pipeline" in the pipeline filter dropdown on the analytics page
- THEN only leads assigned to the Sales Pipeline MUST be reflected in all analytics widgets

#### Scenario 19: Empty analytics state

- GIVEN no leads exist in the system
- WHEN the user views the Rapportage page
- THEN each widget MUST display an empty state message
- AND no JavaScript errors MUST occur from processing an empty dataset

---

### REQ-LM-007: Pipeline Analytics — Source Performance [V1]

The system MUST provide a source performance report showing lead volume and conversion metrics per lead source.

#### Scenario 20: View source performance table

- GIVEN leads from sources: website (8 leads, 2 won), referral (5 leads, 2 won), event (3 leads, 1 won)
- WHEN the user views the "Bronprestaties" widget on the Rapportage page
- THEN a table (`CnTableWidget`) MUST display one row per source with columns:
  - Bron (source label)
  - Totaal leads
  - Gewonnen
  - Conversieratio (won/total as %)
  - Gem. dealwaarde (average value of won leads)
- AND rows MUST be sortable by any column

#### Scenario 21: Source without any closed leads

- GIVEN a source "cold-call" with 4 open leads and 0 won leads
- WHEN the user views the source performance table
- THEN the "cold-call" row MUST show conversieratio: 0% and gem. dealwaarde: "—"
- AND the row MUST still be present in the table

---

### REQ-LM-008: Pipeline Analytics — Win/Loss Analysis [V1]

The system MUST provide a win/loss analysis widget showing overall pipeline conversion health and averages.

#### Scenario 22: View win/loss summary

- GIVEN 8 won leads (avg value EUR 22,500, avg 42 days to close) and 5 lost leads over the selected period
- WHEN the user views the "Gewonnen/Verloren" widget
- THEN a pie chart MUST show the won vs lost count visually
- AND `CnStatsBlock` KPI cards MUST display:
  - Winscore: 61.5%
  - Gewonnen: 8 deals
  - Verloren: 5 deals
  - Gem. dealwaarde gewonnen: EUR 22,500
  - Gem. doorlooptijd: 42 dagen

#### Scenario 23: Win/loss date range filter

- GIVEN closed leads spread over 12 months
- WHEN the user selects date range "Afgelopen 3 maanden" from the filter
- THEN the win/loss widget MUST recalculate using only leads closed within those 3 months
- AND the pipeline funnel and source performance widgets MUST also respect the date filter

---

### REQ-LM-009: Non-Admin Pipeline Access [MVP]

All operational lead management features MUST be accessible to non-admin Nextcloud users. Admin access is only required for pipeline configuration (creating/editing pipeline schemas and stages).

#### Scenario 24: Non-admin user creates a lead

- GIVEN a Nextcloud user who is NOT a member of the admin group
- WHEN they navigate to the Leads section and click "Nieuwe lead"
- THEN the lead creation form MUST open and function normally
- AND submitting the form MUST create the lead with HTTP 201 response
- AND no `403 Forbidden` response MUST occur at any point in the flow

#### Scenario 25: Non-admin user moves a lead between pipeline stages

- GIVEN a non-admin user viewing the pipeline kanban board
- WHEN they drag a lead card from "Nieuw" to "Gekwalificeerd" or use the quick action menu to change stage
- THEN the PUT/PATCH request to OpenRegister MUST return HTTP 200
- AND the stage change MUST be reflected in the kanban board without errors
- AND no `IGroupManager::isAdmin()` check MUST block this operation on the Pipelinq side

#### Scenario 26: Non-admin user views analytics

- GIVEN a non-admin authenticated user
- WHEN they navigate to the "Rapportage" page and the page calls `GET /api/rapportage/pipeline-stats`
- THEN the endpoint MUST return HTTP 200 with analytics data
- AND the page MUST load and display all four analytics widgets normally

#### Scenario 27: Admin configuration remains protected

- GIVEN a non-admin user
- WHEN they navigate to the Nextcloud admin settings page for Pipelinq (`/settings/admin/pipelinq`)
- THEN Nextcloud's built-in admin guard MUST prevent access (HTTP 403 or redirect)
- AND the main app navigation MUST NOT show admin-only configuration items to non-admin users
