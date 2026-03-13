# Lead Management Specification

## Purpose

Lead management handles sales opportunities -- from first contact through to won or lost. A lead is a unified entity (no separate Opportunity split) that flows through configurable pipeline stages. Pipeline stages encode qualification level, making a separate conversion step unnecessary.

**Standards**: Schema.org (`Demand`), Industry CRM consensus (HubSpot, Salesforce, EspoCRM)
**Primary feature tier**: MVP (with V1 and Enterprise enhancements noted per requirement)

## Data Model

See [ARCHITECTURE.md](../../../docs/ARCHITECTURE.md) for the full Lead entity definition with Schema.org mappings.

### Lead Entity Summary

| Property | Type | Required | Default | Validation |
|----------|------|----------|---------|------------|
| `title` | string | Yes | -- | Non-empty, max 255 chars |
| `description` | string | No | -- | Max 5000 chars |
| `client` | reference | No | -- | MUST reference a valid client object |
| `contact` | reference | No | -- | MUST reference a valid contact object |
| `source` | enum | No | -- | One of: website, email, phone, referral, partner, campaign, social_media, event, other |
| `value` | number | No | -- | MUST be non-negative (>= 0) |
| `currency` | string (ISO 4217) | No | EUR | Valid ISO 4217 code |
| `probability` | integer | No | -- | MUST be 0--100 inclusive |
| `expectedCloseDate` | date | No | -- | SHOULD be today or in the future on creation |
| `pipeline` | reference | No | -- | MUST reference a valid pipeline object |
| `stage` | reference | No | -- | MUST reference a valid stage within the referenced pipeline |
| `stageOrder` | integer | No | 0 | Non-negative integer |
| `assignedTo` | string (user UID) | No | -- | MUST reference a valid Nextcloud user UID |
| `priority` | enum | No | normal | One of: low, normal, high, urgent |
| `category` | string | No | -- | Max 100 chars |

---

## Requirements

### REQ-LEAD-001: Lead CRUD [MVP]

The system MUST support creating, reading, updating, and deleting lead records. Each lead MUST have a `title`. All leads are stored as OpenRegister objects in the `pipelinq` register using the `lead` schema.

#### Scenario 1: Create a minimal lead

- GIVEN a user with CRM access
- WHEN they submit a new lead form with title "Website redesign project"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Demand`
- AND the lead MUST have `priority` defaulted to `normal`
- AND if a default pipeline exists, the lead MUST be placed on the first non-closed stage of that pipeline
- AND the audit trail MUST record the creation event with the creating user's identity

#### Scenario 2: Create a lead with full sales fields

- GIVEN a new lead form
- WHEN the user enters:
  - title: "Gemeente ABC digital transformation"
  - value: 25000
  - currency: EUR
  - probability: 40
  - expectedCloseDate: "2026-06-01"
  - source: "referral"
  - priority: "high"
  - category: "Consulting"
- THEN the system MUST store all fields on the lead object
- AND the lead MUST appear in the lead list with value displayed as "EUR 25,000"

#### Scenario 3: Create a lead linked to client and contact

- GIVEN an existing client "Gemeente Utrecht" with contact person "Petra Jansen" (Sales Manager)
- WHEN the user creates a lead titled "IT Infrastructure Upgrade" and selects the client and contact
- THEN the lead MUST store a `client` reference to the Gemeente Utrecht object
- AND the lead MUST store a `contact` reference to Petra Jansen
- AND the lead MUST appear on the client's detail view under the "Leads" section
- AND the contact person MUST be displayed on the lead detail view with name, email, and job title

#### Scenario 4: Update a lead

- GIVEN an existing lead "Gemeente ABC digital transformation" with value 25000
- WHEN the user updates the value to 30000 and changes priority from "normal" to "high"
- THEN the system MUST update the OpenRegister object with the new values
- AND the audit trail MUST record each changed field (old value -> new value)
- AND the updated values MUST be immediately reflected in all views (list, detail, kanban card)

#### Scenario 5: Delete a lead

- GIVEN an existing lead "Cancelled project" in stage "New"
- WHEN the user deletes the lead
- THEN the system MUST remove the OpenRegister object
- AND the lead MUST disappear from all views (lead list, pipeline kanban, client detail, My Work)
- AND the audit trail MUST record the deletion

#### Scenario 6: Delete a lead in a closed stage

- GIVEN a lead "Won deal" in stage "Won" (isClosed: true, isWon: true)
- WHEN the user deletes the lead
- THEN the system MUST allow deletion regardless of stage
- AND a confirmation dialog SHOULD warn: "This lead is marked as Won. Are you sure you want to delete it?"

---

### REQ-LEAD-002: Lead Validation [MVP]

The system MUST enforce validation rules on lead properties to maintain data integrity.

#### Scenario 7: Reject lead without title

- GIVEN a user creating a new lead
- WHEN they submit the form without a title (empty string or missing)
- THEN the system MUST reject the request with validation error "Title is required"
- AND the lead MUST NOT be created
- AND the form MUST highlight the title field as invalid

#### Scenario 8: Reject negative value

- GIVEN a user creating or editing a lead
- WHEN they enter a value of -5000
- THEN the system MUST reject the request with validation error "Value must be non-negative"
- AND the field MUST be highlighted as invalid

#### Scenario 9: Reject out-of-range probability

- GIVEN a user creating or editing a lead
- WHEN they enter probability of 150
- THEN the system MUST reject the request with validation error "Probability must be between 0 and 100"

#### Scenario 10: Warn on past expected close date

- GIVEN a user creating a new lead
- WHEN they set expectedCloseDate to "2025-01-15" (a date in the past)
- THEN the system SHOULD display a warning: "Expected close date is in the past"
- BUT the system MUST still allow saving (existing leads may legitimately have past dates after import)

#### Scenario 11: Reject assignment to non-existent user

- GIVEN a user editing a lead
- WHEN they attempt to assign the lead to user UID "nonexistent_user"
- THEN the system MUST reject the request with validation error "User not found"
- AND the assignedTo field MUST NOT be updated

---

### REQ-LEAD-003: Lead List View [MVP]

The system MUST provide a list view of all leads with search, sort, and filter capabilities. The list view is the primary navigation for leads and MUST support efficient browsing of large datasets.

#### Scenario 12: Display lead list with key columns

- GIVEN 15 leads exist in the system
- WHEN the user navigates to the Leads section
- THEN the system MUST display a table with columns: title, value, stage, priority, source, assignee, expected close date
- AND each row MUST be clickable to navigate to the lead detail view
- AND the list MUST support pagination (default page size SHOULD be 25)

#### Scenario 13: Search leads by title and description

- GIVEN leads titled "Gemeente ABC deal" (description: "Digital transformation consulting") and "TechCorp website" (description: "New corporate website")
- WHEN the user types "digital" in the search box
- THEN the results MUST include "Gemeente ABC deal" (matches description)
- AND the results MUST NOT include "TechCorp website"
- AND the search MUST be case-insensitive

#### Scenario 14: Filter leads by source

- GIVEN 10 leads: 4 from "website", 3 from "referral", 2 from "phone", 1 from "email"
- WHEN the user applies the filter source = "referral"
- THEN exactly 3 leads MUST be shown
- AND the filter state MUST be visually indicated (e.g., badge on filter button)

#### Scenario 15: Filter leads by stage

- GIVEN leads distributed across stages New (3), Contacted (2), Qualified (4), Won (1), Lost (2)
- WHEN the user filters by stage "Qualified"
- THEN exactly 4 leads MUST be shown
- AND the user SHOULD be able to select multiple stages (e.g., Qualified + Proposal)

#### Scenario 16: Filter leads by assignee

- GIVEN leads assigned to "jan" (5 leads), "maria" (3 leads), and unassigned (2 leads)
- WHEN the user filters by assignee = "jan"
- THEN exactly 5 leads MUST be shown
- AND a special filter option "Unassigned" MUST show leads with no assignee

#### Scenario 17: Sort leads by value descending

- GIVEN leads with values EUR 5,000 / EUR 25,000 / EUR 12,000 / null
- WHEN the user sorts by value descending
- THEN leads MUST appear in order: EUR 25,000, EUR 12,000, EUR 5,000, and leads with null value SHOULD appear last

#### Scenario 18: Sort leads by expected close date

- GIVEN leads with close dates 2026-03-01, 2026-06-15, 2026-02-20, and one with no close date
- WHEN the user sorts by expected close date ascending
- THEN leads MUST appear: 2026-02-20, 2026-03-01, 2026-06-15, and the lead with no date SHOULD appear last

#### Scenario 19: Sort leads by priority

- GIVEN leads with priorities: urgent, low, high, normal, urgent
- WHEN the user sorts by priority descending
- THEN leads MUST appear in order: urgent (2), high (1), normal (1), low (1)
- AND priority MUST use the ranking: urgent > high > normal > low

#### Scenario 20: Combine filter and sort

- GIVEN 20 leads across multiple sources and stages
- WHEN the user filters by source "website" AND sorts by value descending
- THEN only website-sourced leads MUST be shown, ordered by descending value
- AND clearing the filter MUST restore the full list while preserving the sort

---

### REQ-LEAD-004: Lead Detail View [MVP]

The system MUST provide a detail view for each lead that displays all properties, pipeline progress, linked entities, and activity timeline. The layout MUST follow the wireframe in DESIGN-REFERENCES.md Section 3.4.

#### Scenario 21: View lead detail -- core information

- GIVEN a lead "Acme Corp deal" with value EUR 5,000, probability 40%, source "Website", priority "Normal", category "Consulting", expectedCloseDate "2026-03-05"
- WHEN the user navigates to the lead detail view
- THEN the system MUST display the Core Info panel with all properties
- AND value MUST be formatted with currency symbol and thousand separators ("EUR 5,000")
- AND probability MUST be displayed as a percentage ("40%")
- AND expected close date MUST be formatted in the user's locale

#### Scenario 22: View lead detail -- pipeline progress indicator

- GIVEN a lead on the "Sales Pipeline" currently in stage "Qualified" (3rd of 7 stages)
- WHEN the user views the lead detail
- THEN the system MUST display a vertical pipeline progress indicator showing all stages
- AND completed stages (New, Contacted) MUST be shown with a filled indicator
- AND the current stage (Qualified) MUST be highlighted as "current"
- AND future stages (Proposal, Negotiation, Won, Lost) MUST be shown with an empty indicator
- AND a "Move to Proposal" action button MUST be displayed to advance to the next stage
- AND the pipeline name ("Sales Pipeline") MUST be displayed below the progress indicator

#### Scenario 23: View lead detail -- client and contact links

- GIVEN a lead linked to client "Acme Corporation" (email: info@acme.nl, phone: +31 20 555 0123) and contact "Petra Jansen" (email: petra@acme.nl, role: Sales Manager)
- WHEN the user views the lead detail
- THEN the client name MUST be a clickable link navigating to the client detail view
- AND the client's email and phone MUST be displayed
- AND the contact person's name, email, and role MUST be displayed
- AND if no client is linked, the system MUST show "No client linked" with an "Add client" action

#### Scenario 24: View lead detail -- assignee section

- GIVEN a lead assigned to Nextcloud user "jan" (display name "Jan de Vries")
- WHEN the user views the lead detail
- THEN the system MUST display the assignee's display name and avatar
- AND a "Reassign" button MUST be present to change the assignee
- AND if no assignee is set, the system MUST show "Unassigned" with an "Assign" action

#### Scenario 25: View lead detail -- activity timeline

- GIVEN a lead with the following history:
  - Feb 10: Lead created (source: Website, by: system)
  - Feb 15: Stage changed to "Contacted" (by: Jan de Vries)
  - Feb 18: Note added: "Had a great call with Petra..." (by: Jan de Vries)
  - Feb 20: Stage changed to "Qualified" (by: Jan de Vries)
- WHEN the user views the lead detail
- THEN the activity timeline MUST display events in reverse chronological order (newest first)
- AND each event MUST show: date, event description, and actor
- AND an "Add note" button MUST be present at the top of the timeline
- AND the timeline MUST support pagination for leads with extensive history

---

### REQ-LEAD-005: Lead Source Tracking [MVP]

The system MUST support tracking where leads originate from. Source values MUST be one of the predefined enum values.

#### Scenario 26: Set lead source on creation

- GIVEN a user creating a new lead
- WHEN they select source "referral" from the source dropdown
- THEN the lead MUST store `source: "referral"`
- AND the source MUST be displayed in the lead list as a human-readable label ("Referral")
- AND the source MUST be displayed in the lead detail view

#### Scenario 27: Source dropdown shows all options

- GIVEN a user creating or editing a lead
- WHEN they open the source dropdown
- THEN the system MUST display all 9 source options: Website, Email, Phone, Referral, Partner, Campaign, Social Media, Event, Other
- AND the labels MUST be localized (e.g., Dutch: "Doorverwijzing" for Referral)

#### Scenario 28: Leave source unset

- GIVEN a user creating a new lead
- WHEN they leave the source field empty
- THEN the lead MUST be created without a source
- AND the lead list MUST display an empty cell or dash for the source column
- AND filtering by "No source" SHOULD be possible

---

### REQ-LEAD-006: Lead Assignment [MVP]

The system MUST support assigning leads to Nextcloud users. Assignment determines which user is responsible for the lead and controls visibility in the My Work view.

#### Scenario 29: Assign lead to a user

- GIVEN an unassigned lead "TechCorp inquiry"
- WHEN the user assigns it to Nextcloud user "maria" (display name "Maria van Dijk")
- THEN the lead MUST store `assignedTo: "maria"`
- AND the lead MUST appear in Maria's My Work view
- AND the lead list and kanban card MUST show Maria's name/avatar as the assignee

#### Scenario 30: Reassign lead to a different user

- GIVEN a lead "TechCorp inquiry" assigned to "jan"
- WHEN the user reassigns it to "pieter"
- THEN the lead MUST update `assignedTo: "pieter"`
- AND the lead MUST disappear from Jan's My Work view
- AND the lead MUST appear in Pieter's My Work view
- AND the audit trail MUST record the reassignment (from "jan" to "pieter")

#### Scenario 31: Unassign a lead

- GIVEN a lead assigned to "maria"
- WHEN the user clears the assignee (sets to empty/null)
- THEN the lead MUST update `assignedTo: null`
- AND the lead MUST disappear from Maria's My Work view
- AND the lead list MUST show the lead as "Unassigned"

#### Scenario 32: Assign lead from user picker

- GIVEN a user opening the assignee selector on a lead
- WHEN they type "jan" in the search field
- THEN the system MUST search Nextcloud users via IUserManager
- AND display matching users with display name and avatar
- AND selecting a user MUST immediately update the assignment

---

### REQ-LEAD-007: Lead Lifecycle via Pipeline Stages [MVP]

A lead's lifecycle from creation to won/lost is driven by pipeline stages. Moving a lead to a closed stage (isClosed: true) represents the end of the sales process for that lead.

#### Scenario 33: Move lead to Won stage

- GIVEN a lead "Gemeente ABC deal" (value: EUR 25,000) in stage "Negotiation"
- WHEN the user moves the lead to stage "Won" (isClosed: true, isWon: true)
- THEN the lead's `stage` reference MUST be updated to the Won stage
- AND the lead's `probability` SHOULD be updated to 100 (the Won stage's probability)
- AND the lead MUST no longer appear in the "Open leads" count on the dashboard
- AND the lead MUST still appear in the pipeline kanban in the collapsed Won column

#### Scenario 34: Move lead to Lost stage

- GIVEN a lead "Failed deal" (value: EUR 10,000) in stage "Proposal"
- WHEN the user moves the lead to stage "Lost" (isClosed: true, isWon: false)
- THEN the lead's `stage` reference MUST be updated to the Lost stage
- AND the lead's `probability` SHOULD be updated to 0
- AND the lead MUST no longer count toward the pipeline's active value total
- AND the lead MUST appear in the collapsed Lost column on the kanban

#### Scenario 35: Reopen a closed lead

- GIVEN a lead in stage "Lost" (isClosed: true)
- WHEN the user moves the lead back to stage "Contacted" (isClosed: false)
- THEN the lead MUST be re-activated on the pipeline
- AND the lead MUST reappear in the "Open leads" count
- AND the lead's probability SHOULD be updated to the Contacted stage's probability (20)
- AND the audit trail MUST record the reopen event

#### Scenario 36: Move lead to closed stage from detail view

- GIVEN a lead detail view showing the pipeline progress indicator
- WHEN the user clicks the "Won" stage in the progress indicator
- THEN a confirmation dialog SHOULD appear: "Mark this lead as Won?"
- AND upon confirmation, the lead MUST be moved to the Won stage
- AND the progress indicator MUST update to show Won as the current stage

---

### REQ-LEAD-008: Lead Value and Probability [MVP]

The system MUST support tracking the monetary value and win probability of leads for pipeline valuation and sales forecasting.

#### Scenario 37: Display value with currency

- GIVEN a lead with value 25000 and currency "EUR"
- WHEN the lead is displayed in any view (list, detail, kanban card)
- THEN the value MUST be formatted as "EUR 25,000" (with locale-appropriate formatting)

#### Scenario 38: Lead with zero value

- GIVEN a user creating a lead for a non-revenue opportunity
- WHEN they set value to 0
- THEN the system MUST accept the value (0 is valid, only negative is rejected)
- AND the lead MUST display "EUR 0" in views

#### Scenario 39: Lead without value

- GIVEN a user creating a lead without entering a value
- WHEN the lead is saved
- THEN the value field MUST be stored as null (not 0)
- AND the lead list MUST display a dash or empty cell for the value column
- AND the lead MUST NOT contribute to pipeline value totals

---

### REQ-LEAD-009: Lead Expected Close Date [MVP]

The system MUST support an expected close date to track when a lead is anticipated to close.

#### Scenario 40: Set expected close date

- GIVEN a user creating a lead
- WHEN they set expectedCloseDate to "2026-06-15"
- THEN the date MUST be stored on the lead
- AND the lead detail MUST display the formatted date
- AND the lead list MUST show the date in the "Due" column

#### Scenario 41: Overdue lead indicator

- GIVEN a lead with expectedCloseDate "2026-02-20" and the current date is "2026-02-25"
- WHEN the lead is displayed in any view
- THEN the system MUST display an overdue indicator (e.g., red text, warning icon)
- AND the overdue duration MUST be shown (e.g., "5 days overdue")
- AND the lead MUST be groupable in the "Overdue" section of My Work

---

### REQ-LEAD-010: Lead Priority [MVP]

The system MUST support four priority levels to enable triage and sorting of leads.

#### Scenario 42: Set priority on creation

- GIVEN a user creating a new lead
- WHEN they select priority "urgent"
- THEN the lead MUST store `priority: "urgent"`
- AND the kanban card MUST display a visible priority badge
- AND only non-normal priorities (low, high, urgent) SHOULD show a visual badge to reduce noise

#### Scenario 43: Default priority

- GIVEN a user creating a new lead without selecting a priority
- WHEN the lead is saved
- THEN the priority MUST default to "normal"
- AND no priority badge SHOULD be displayed on the kanban card (normal is the baseline)

#### Scenario 44: Priority sort order

- GIVEN leads with all four priority levels
- WHEN sorting or grouping by priority
- THEN the system MUST use the ranking: urgent (highest) > high > normal > low (lowest)

---

### REQ-LEAD-011: Quick Actions on Lead Cards [MVP]

The system MUST support quick actions on lead cards (in kanban and list views) to enable common operations without opening the detail view. This follows the pattern described in DESIGN-REFERENCES.md Section 3.2 and is a standard CRM pattern (HubSpot, Salesforce).

#### Scenario 45: Quick move stage from kanban card

- GIVEN a lead card "Gemeente ABC deal" in the "New" column
- WHEN the user right-clicks or opens the card action menu and selects "Move to stage"
- THEN a dropdown MUST appear listing all stages in the pipeline
- AND selecting "Qualified" MUST move the lead to the Qualified stage
- AND the card MUST animate from the New column to the Qualified column

#### Scenario 46: Quick assign from kanban card

- GIVEN an unassigned lead card on the kanban board
- WHEN the user opens the card action menu and selects "Assign"
- THEN a user picker MUST appear
- AND selecting a user MUST immediately update the assignment
- AND the card MUST update to show the new assignee's avatar

#### Scenario 47: Quick set priority from kanban card

- GIVEN a lead card with priority "normal"
- WHEN the user opens the card action menu and selects "Set priority"
- THEN options for low, normal, high, urgent MUST appear
- AND selecting "urgent" MUST immediately update the priority
- AND the card MUST display the urgent priority badge

---

### REQ-LEAD-012: Stale Lead Detection [V1]

The system SHOULD detect leads with no activity for a configurable number of days and highlight them to prevent forgotten opportunities.

#### Scenario 48: Identify stale leads

- GIVEN a lead "Forgotten deal" with last activity on 2026-01-15 and the current date is 2026-02-25 (41 days gap)
- AND the stale threshold is configured to 30 days
- WHEN the system evaluates staleness
- THEN the lead MUST be flagged as stale
- AND the lead list and kanban card MUST display a stale indicator (e.g., "41 days since last activity")
- AND a filter option "Stale leads" SHOULD be available in the lead list

#### Scenario 49: Lead becomes active again

- GIVEN a stale lead "Forgotten deal"
- WHEN a user adds a note, changes the stage, or updates any field
- THEN the staleness MUST be recalculated from the new activity date
- AND the stale indicator MUST be removed if within the threshold

---

### REQ-LEAD-013: Aging Indicator [V1]

The system SHOULD display how long a lead has been in its current stage to help identify bottlenecks.

#### Scenario 50: Display days in current stage

- GIVEN a lead that entered the "Qualified" stage on 2026-02-20 and the current date is 2026-02-25
- WHEN the lead is displayed in the kanban card or detail view
- THEN the system MUST show "5d in stage" (or equivalent)
- AND the aging value MUST be calculated from the last stage-change date

#### Scenario 51: Aging resets on stage change

- GIVEN a lead showing "12d in stage" for the "Proposal" stage
- WHEN the lead is moved to the "Negotiation" stage
- THEN the aging counter MUST reset to "0d in stage"
- AND the previous stage duration SHOULD be preserved in the activity timeline

---

### REQ-LEAD-014: Lead Import/Export CSV [V1]

The system SHOULD support importing leads from CSV and exporting leads to CSV for migration and reporting.

#### Scenario 52: Export current lead list to CSV

- GIVEN a filtered lead list showing 8 leads
- WHEN the user clicks "Export CSV"
- THEN the system MUST generate a CSV file containing all displayed leads
- AND the CSV MUST include columns: title, description, value, currency, probability, expectedCloseDate, source, priority, stage, assignee, client, category
- AND the file MUST be downloaded to the user's browser

#### Scenario 53: Import leads from CSV

- GIVEN a CSV file with columns: title, value, source, priority
- WHEN the user uploads the CSV via the import function
- THEN the system MUST create lead objects for each valid row
- AND leads MUST be placed on the default pipeline's first stage
- AND a summary MUST be shown: "Imported 12 leads. 3 rows skipped (missing title)."

#### Scenario 54: Import with validation errors

- GIVEN a CSV file where row 3 has value "-500" and row 7 has no title
- WHEN the user imports the file
- THEN the system MUST skip invalid rows
- AND the summary MUST list specific errors: "Row 3: Value must be non-negative. Row 7: Title is required."
- AND valid rows MUST still be imported

---

### REQ-LEAD-015: Error Scenarios [MVP]

The system MUST handle error conditions gracefully and provide meaningful feedback to users.

#### Scenario 55: Create lead when OpenRegister is unavailable

- GIVEN the OpenRegister API is unreachable
- WHEN the user attempts to create a lead
- THEN the system MUST display an error message: "Could not save lead. Please try again later."
- AND the form data MUST be preserved so the user does not lose their input

#### Scenario 56: Move lead to non-existent stage

- GIVEN a lead on a pipeline
- WHEN a stage is deleted by an admin while a user is viewing the kanban
- AND the user attempts to drag a lead to the deleted stage
- THEN the system MUST display an error: "Stage no longer exists. Please refresh the page."
- AND the lead MUST remain in its current stage

#### Scenario 57: Edit lead concurrently modified by another user

- GIVEN two users editing the same lead simultaneously
- WHEN user A saves changes after user B has already saved
- THEN the system SHOULD detect the conflict (via OpenRegister versioning)
- AND display a message: "This lead was modified by another user. Please reload and try again."

---

## UI Reference

### Lead List View
See DESIGN-REFERENCES.md Section 3.3 for the pipeline list view wireframe, which applies to the lead list with columns: type badge, title, stage, value, due date, assigned user.

### Lead Detail View
See DESIGN-REFERENCES.md Section 3.4 for the complete lead detail wireframe showing:
- Core Info panel (left column)
- Pipeline Progress indicator (right column, vertical stage list)
- Client and Contact links (left column, below core info)
- Assigned To section (right column)
- Activity Timeline (full width, bottom)

### Kanban Card Anatomy
See DESIGN-REFERENCES.md Section 3.2 card anatomy:
- Entity type badge: [LEAD] in a distinct color
- Title (clickable to detail view)
- Value (EUR formatted)
- Priority badge (only if not normal)
- Due date + assignee avatar
- Overdue warning (if applicable)
- Days in current stage (V1)
