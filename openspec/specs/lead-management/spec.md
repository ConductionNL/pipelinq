# Lead Management Specification

## Status: implemented

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
| `tags` | array of strings | No | [] | Each tag max 100 chars, max 20 tags per lead |
| `qualificationScore` | integer | No | -- | MUST be 0--100 inclusive |

---

## Requirements

### Requirement: Lead CRUD [MVP]

The system MUST support creating, reading, updating, and deleting lead records. Each lead MUST have a `title`. All leads are stored as OpenRegister objects in the `pipelinq` register using the `lead` schema.

#### Scenario: Create a minimal lead

- GIVEN a user with CRM access
- WHEN they submit a new lead form with title "Website redesign project"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Demand`
- AND the lead MUST have `priority` defaulted to `normal`
- AND if a default pipeline exists, the lead MUST be placed on the first non-closed stage of that pipeline
- AND the audit trail MUST record the creation event with the creating user's identity

#### Scenario: Create a lead with full sales fields

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

#### Scenario: Create a lead linked to client and contact

- GIVEN an existing client "Gemeente Utrecht" with contact person "Petra Jansen" (Sales Manager)
- WHEN the user creates a lead titled "IT Infrastructure Upgrade" and selects the client and contact
- THEN the lead MUST store a `client` reference to the Gemeente Utrecht object
- AND the lead MUST store a `contact` reference to Petra Jansen
- AND the lead MUST appear on the client's detail view under the "Leads" section
- AND the contact person MUST be displayed on the lead detail view with name, email, and job title

#### Scenario: Update a lead

- GIVEN an existing lead "Gemeente ABC digital transformation" with value 25000
- WHEN the user updates the value to 30000 and changes priority from "normal" to "high"
- THEN the system MUST update the OpenRegister object with the new values
- AND the audit trail MUST record each changed field (old value -> new value)
- AND the updated values MUST be immediately reflected in all views (list, detail, kanban card)

#### Scenario: Delete a lead

- GIVEN an existing lead "Cancelled project" in stage "New"
- WHEN the user deletes the lead
- THEN the system MUST remove the OpenRegister object
- AND the lead MUST disappear from all views (lead list, pipeline kanban, client detail, My Work)
- AND the audit trail MUST record the deletion

#### Scenario: Delete a lead in a closed stage

- GIVEN a lead "Won deal" in stage "Won" (isClosed: true, isWon: true)
- WHEN the user deletes the lead
- THEN the system MUST allow deletion regardless of stage
- AND a confirmation dialog SHOULD warn: "This lead is marked as Won. Are you sure you want to delete it?"

---

### Requirement: Lead Validation [MVP]

The system MUST enforce validation rules on lead properties to maintain data integrity.

#### Scenario: Reject lead without title

- GIVEN a user creating a new lead
- WHEN they submit the form without a title (empty string or missing)
- THEN the system MUST reject the request with validation error "Title is required"
- AND the lead MUST NOT be created
- AND the form MUST highlight the title field as invalid

#### Scenario: Reject negative value

- GIVEN a user creating or editing a lead
- WHEN they enter a value of -5000
- THEN the system MUST reject the request with validation error "Value must be non-negative"
- AND the field MUST be highlighted as invalid

#### Scenario: Reject out-of-range probability

- GIVEN a user creating or editing a lead
- WHEN they enter probability of 150
- THEN the system MUST reject the request with validation error "Probability must be between 0 and 100"

#### Scenario: Warn on past expected close date

- GIVEN a user creating a new lead
- WHEN they set expectedCloseDate to "2025-01-15" (a date in the past)
- THEN the system SHOULD display a warning: "Expected close date is in the past"
- BUT the system MUST still allow saving (existing leads may legitimately have past dates after import)

#### Scenario: Reject assignment to non-existent user

- GIVEN a user editing a lead
- WHEN they attempt to assign the lead to user UID "nonexistent_user"
- THEN the system MUST reject the request with validation error "User not found"
- AND the assignedTo field MUST NOT be updated

---

### Requirement: Lead List View [MVP]

The system MUST provide a list view of all leads with search, sort, and filter capabilities. The list view is the primary navigation for leads and MUST support efficient browsing of large datasets.

#### Scenario: Display lead list with key columns

- GIVEN 15 leads exist in the system
- WHEN the user navigates to the Leads section
- THEN the system MUST display a table with columns: title, value, stage, priority, source, assignee, expected close date
- AND each row MUST be clickable to navigate to the lead detail view
- AND the list MUST support pagination (default page size SHOULD be 25)

#### Scenario: Search leads by title and description

- GIVEN leads titled "Gemeente ABC deal" (description: "Digital transformation consulting") and "TechCorp website" (description: "New corporate website")
- WHEN the user types "digital" in the search box
- THEN the results MUST include "Gemeente ABC deal" (matches description)
- AND the results MUST NOT include "TechCorp website"
- AND the search MUST be case-insensitive

#### Scenario: Filter leads by source

- GIVEN 10 leads: 4 from "website", 3 from "referral", 2 from "phone", 1 from "email"
- WHEN the user applies the filter source = "referral"
- THEN exactly 3 leads MUST be shown
- AND the filter state MUST be visually indicated (e.g., badge on filter button)

#### Scenario: Filter leads by stage

- GIVEN leads distributed across stages New (3), Contacted (2), Qualified (4), Won (1), Lost (2)
- WHEN the user filters by stage "Qualified"
- THEN exactly 4 leads MUST be shown
- AND the user SHOULD be able to select multiple stages (e.g., Qualified + Proposal)

#### Scenario: Filter leads by assignee

- GIVEN leads assigned to "jan" (5 leads), "maria" (3 leads), and unassigned (2 leads)
- WHEN the user filters by assignee = "jan"
- THEN exactly 5 leads MUST be shown
- AND a special filter option "Unassigned" MUST show leads with no assignee

#### Scenario: Sort leads by value descending

- GIVEN leads with values EUR 5,000 / EUR 25,000 / EUR 12,000 / null
- WHEN the user sorts by value descending
- THEN leads MUST appear in order: EUR 25,000, EUR 12,000, EUR 5,000, and leads with null value SHOULD appear last

#### Scenario: Sort leads by expected close date

- GIVEN leads with close dates 2026-03-01, 2026-06-15, 2026-02-20, and one with no close date
- WHEN the user sorts by expected close date ascending
- THEN leads MUST appear: 2026-02-20, 2026-03-01, 2026-06-15, and the lead with no date SHOULD appear last

#### Scenario: Sort leads by priority

- GIVEN leads with priorities: urgent, low, high, normal, urgent
- WHEN the user sorts by priority descending
- THEN leads MUST appear in order: urgent (2), high (1), normal (1), low (1)
- AND priority MUST use the ranking: urgent > high > normal > low

#### Scenario: Combine filter and sort

- GIVEN 20 leads across multiple sources and stages
- WHEN the user filters by source "website" AND sorts by value descending
- THEN only website-sourced leads MUST be shown, ordered by descending value
- AND clearing the filter MUST restore the full list while preserving the sort

---

### Requirement: Lead Detail View [MVP]

The system MUST provide a detail view for each lead that displays all properties, pipeline progress, linked entities, and activity timeline. The layout MUST follow the wireframe in DESIGN-REFERENCES.md Section 3.4.

#### Scenario: View lead detail -- core information

- GIVEN a lead "Acme Corp deal" with value EUR 5,000, probability 40%, source "Website", priority "Normal", category "Consulting", expectedCloseDate "2026-03-05"
- WHEN the user navigates to the lead detail view
- THEN the system MUST display the Core Info panel with all properties
- AND value MUST be formatted with currency symbol and thousand separators ("EUR 5,000")
- AND probability MUST be displayed as a percentage ("40%")
- AND expected close date MUST be formatted in the user's locale

#### Scenario: View lead detail -- pipeline progress indicator

- GIVEN a lead on the "Sales Pipeline" currently in stage "Qualified" (3rd of 7 stages)
- WHEN the user views the lead detail
- THEN the system MUST display a vertical pipeline progress indicator showing all stages
- AND completed stages (New, Contacted) MUST be shown with a filled indicator
- AND the current stage (Qualified) MUST be highlighted as "current"
- AND future stages (Proposal, Negotiation, Won, Lost) MUST be shown with an empty indicator
- AND a "Move to Proposal" action button MUST be displayed to advance to the next stage
- AND the pipeline name ("Sales Pipeline") MUST be displayed below the progress indicator

#### Scenario: View lead detail -- client and contact links

- GIVEN a lead linked to client "Acme Corporation" (email: info@acme.nl, phone: +31 20 555 0123) and contact "Petra Jansen" (email: petra@acme.nl, role: Sales Manager)
- WHEN the user views the lead detail
- THEN the client name MUST be a clickable link navigating to the client detail view
- AND the client's email and phone MUST be displayed
- AND the contact person's name, email, and role MUST be displayed
- AND if no client is linked, the system MUST show "No client linked" with an "Add client" action

#### Scenario: View lead detail -- assignee section

- GIVEN a lead assigned to Nextcloud user "jan" (display name "Jan de Vries")
- WHEN the user views the lead detail
- THEN the system MUST display the assignee's display name and avatar
- AND a "Reassign" button MUST be present to change the assignee
- AND if no assignee is set, the system MUST show "Unassigned" with an "Assign" action

#### Scenario: View lead detail -- activity timeline

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

### Requirement: Lead Source Tracking [MVP]

The system MUST support tracking where leads originate from. Source values are managed via the lead sources admin settings (TagManager-based CRUD at `/api/settings/lead-sources`).

#### Scenario: Set lead source on creation

- GIVEN a user creating a new lead
- WHEN they select source "referral" from the source dropdown
- THEN the lead MUST store `source: "referral"`
- AND the source MUST be displayed in the lead list as a human-readable label ("Referral")
- AND the source MUST be displayed in the lead detail view

#### Scenario: Source dropdown shows all options

- GIVEN a user creating or editing a lead
- WHEN they open the source dropdown
- THEN the system MUST display all configured source options (defaults: Website, Email, Phone, Referral, Partner, Campaign, Social Media, Event, Other)
- AND the labels MUST be localized (e.g., Dutch: "Doorverwijzing" for Referral)
- AND admins MUST be able to add, rename, or remove source options via the lead source settings

#### Scenario: Leave source unset

- GIVEN a user creating a new lead
- WHEN they leave the source field empty
- THEN the lead MUST be created without a source
- AND the lead list MUST display an empty cell or dash for the source column
- AND filtering by "No source" SHOULD be possible

---

### Requirement: Lead Assignment [MVP]

The system MUST support assigning leads to Nextcloud users. Assignment determines which user is responsible for the lead and controls visibility in the My Work view.

#### Scenario: Assign lead to a user

- GIVEN an unassigned lead "TechCorp inquiry"
- WHEN the user assigns it to Nextcloud user "maria" (display name "Maria van Dijk")
- THEN the lead MUST store `assignedTo: "maria"`
- AND the lead MUST appear in Maria's My Work view
- AND the lead list and kanban card MUST show Maria's name/avatar as the assignee

#### Scenario: Reassign lead to a different user

- GIVEN a lead "TechCorp inquiry" assigned to "jan"
- WHEN the user reassigns it to "pieter"
- THEN the lead MUST update `assignedTo: "pieter"`
- AND the lead MUST disappear from Jan's My Work view
- AND the lead MUST appear in Pieter's My Work view
- AND the audit trail MUST record the reassignment (from "jan" to "pieter")

#### Scenario: Unassign a lead

- GIVEN a lead assigned to "maria"
- WHEN the user clears the assignee (sets to empty/null)
- THEN the lead MUST update `assignedTo: null`
- AND the lead MUST disappear from Maria's My Work view
- AND the lead list MUST show the lead as "Unassigned"

#### Scenario: Assign lead from user picker

- GIVEN a user opening the assignee selector on a lead
- WHEN they type "jan" in the search field
- THEN the system MUST search Nextcloud users via IUserManager
- AND display matching users with display name and avatar
- AND selecting a user MUST immediately update the assignment

---

### Requirement: Lead Lifecycle via Pipeline Stages [MVP]

A lead's lifecycle from creation to won/lost MUST be driven by pipeline stages. Moving a lead to a closed stage (isClosed: true) represents the end of the sales process for that lead.

#### Scenario: Move lead to Won stage

- GIVEN a lead "Gemeente ABC deal" (value: EUR 25,000) in stage "Negotiation"
- WHEN the user moves the lead to stage "Won" (isClosed: true, isWon: true)
- THEN the lead's `stage` reference MUST be updated to the Won stage
- AND the lead's `probability` SHOULD be updated to 100 (the Won stage's probability)
- AND the lead MUST no longer appear in the "Open leads" count on the dashboard
- AND the lead MUST still appear in the pipeline kanban in the collapsed Won column

#### Scenario: Move lead to Lost stage

- GIVEN a lead "Failed deal" (value: EUR 10,000) in stage "Proposal"
- WHEN the user moves the lead to stage "Lost" (isClosed: true, isWon: false)
- THEN the lead's `stage` reference MUST be updated to the Lost stage
- AND the lead's `probability` SHOULD be updated to 0
- AND the lead MUST no longer count toward the pipeline's active value total
- AND the lead MUST appear in the collapsed Lost column on the kanban

#### Scenario: Reopen a closed lead

- GIVEN a lead in stage "Lost" (isClosed: true)
- WHEN the user moves the lead back to stage "Contacted" (isClosed: false)
- THEN the lead MUST be re-activated on the pipeline
- AND the lead MUST reappear in the "Open leads" count
- AND the lead's probability SHOULD be updated to the Contacted stage's probability (20)
- AND the audit trail MUST record the reopen event

#### Scenario: Move lead to closed stage from detail view

- GIVEN a lead detail view showing the pipeline progress indicator
- WHEN the user clicks the "Won" stage in the progress indicator
- THEN a confirmation dialog SHOULD appear: "Mark this lead as Won?"
- AND upon confirmation, the lead MUST be moved to the Won stage
- AND the progress indicator MUST update to show Won as the current stage

---

### Requirement: Lead Value and Probability [MVP]

The system MUST support tracking the monetary value and win probability of leads for pipeline valuation and sales forecasting.

#### Scenario: Display value with currency

- GIVEN a lead with value 25000 and currency "EUR"
- WHEN the lead is displayed in any view (list, detail, kanban card)
- THEN the value MUST be formatted as "EUR 25,000" (with locale-appropriate formatting)

#### Scenario: Lead with zero value

- GIVEN a user creating a lead for a non-revenue opportunity
- WHEN they set value to 0
- THEN the system MUST accept the value (0 is valid, only negative is rejected)
- AND the lead MUST display "EUR 0" in views

#### Scenario: Lead without value

- GIVEN a user creating a lead without entering a value
- WHEN the lead is saved
- THEN the value field MUST be stored as null (not 0)
- AND the lead list MUST display a dash or empty cell for the value column
- AND the lead MUST NOT contribute to pipeline value totals

---

### Requirement: Lead Expected Close Date [MVP]

The system MUST support an expected close date to track when a lead is anticipated to close.

#### Scenario: Set expected close date

- GIVEN a user creating a lead
- WHEN they set expectedCloseDate to "2026-06-15"
- THEN the date MUST be stored on the lead
- AND the lead detail MUST display the formatted date
- AND the lead list MUST show the date in the "Due" column

#### Scenario: Overdue lead indicator

- GIVEN a lead with expectedCloseDate "2026-02-20" and the current date is "2026-02-25"
- WHEN the lead is displayed in any view
- THEN the system MUST display an overdue indicator (e.g., red text, warning icon)
- AND the overdue duration MUST be shown (e.g., "5 days overdue")
- AND the lead MUST be groupable in the "Overdue" section of My Work

---

### Requirement: Lead Priority [MVP]

The system MUST support four priority levels to enable triage and sorting of leads.

#### Scenario: Set priority on creation

- GIVEN a user creating a new lead
- WHEN they select priority "urgent"
- THEN the lead MUST store `priority: "urgent"`
- AND the kanban card MUST display a visible priority badge
- AND only non-normal priorities (low, high, urgent) SHOULD show a visual badge to reduce noise

#### Scenario: Default priority

- GIVEN a user creating a new lead without selecting a priority
- WHEN the lead is saved
- THEN the priority MUST default to "normal"
- AND no priority badge SHOULD be displayed on the kanban card (normal is the baseline)

#### Scenario: Priority sort order

- GIVEN leads with all four priority levels
- WHEN sorting or grouping by priority
- THEN the system MUST use the ranking: urgent (highest) > high > normal > low (lowest)

---

### Requirement: Quick Actions on Lead Cards [MVP]

The system MUST support quick actions on lead cards (in kanban and list views) to enable common operations without opening the detail view. This follows the pattern described in DESIGN-REFERENCES.md Section 3.2 and is a standard CRM pattern (HubSpot, Salesforce).

#### Scenario: Quick move stage from kanban card

- GIVEN a lead card "Gemeente ABC deal" in the "New" column
- WHEN the user right-clicks or opens the card action menu and selects "Move to stage"
- THEN a dropdown MUST appear listing all stages in the pipeline
- AND selecting "Qualified" MUST move the lead to the Qualified stage
- AND the card MUST animate from the New column to the Qualified column

#### Scenario: Quick assign from kanban card

- GIVEN an unassigned lead card on the kanban board
- WHEN the user opens the card action menu and selects "Assign"
- THEN a user picker MUST appear
- AND selecting a user MUST immediately update the assignment
- AND the card MUST update to show the new assignee's avatar

#### Scenario: Quick set priority from kanban card

- GIVEN a lead card with priority "normal"
- WHEN the user opens the card action menu and selects "Set priority"
- THEN options for low, normal, high, urgent MUST appear
- AND selecting "urgent" MUST immediately update the priority
- AND the card MUST display the urgent priority badge

---

### Requirement: Stale Lead Detection [V1]

The system MUST detect leads with no activity for a configurable number of days and highlight them to prevent forgotten opportunities.

#### Scenario: Identify stale leads

- GIVEN a lead "Forgotten deal" with last activity on 2026-01-15 and the current date is 2026-02-25 (41 days gap)
- AND the stale threshold is configured to 30 days
- WHEN the system evaluates staleness
- THEN the lead MUST be flagged as stale
- AND the lead list and kanban card MUST display a stale indicator (e.g., "41 days since last activity")
- AND a filter option "Stale leads" SHOULD be available in the lead list

#### Scenario: Lead becomes active again

- GIVEN a stale lead "Forgotten deal"
- WHEN a user adds a note, changes the stage, or updates any field
- THEN the staleness MUST be recalculated from the new activity date
- AND the stale indicator MUST be removed if within the threshold

---

### Requirement: Aging Indicator [V1]

The system MUST display how long a lead has been in its current stage to help identify bottlenecks.

#### Scenario: Display days in current stage

- GIVEN a lead that entered the "Qualified" stage on 2026-02-20 and the current date is 2026-02-25
- WHEN the lead is displayed in the kanban card or detail view
- THEN the system MUST show "5d in stage" (or equivalent)
- AND the aging value MUST be calculated from the last stage-change date

#### Scenario: Aging resets on stage change

- GIVEN a lead showing "12d in stage" for the "Proposal" stage
- WHEN the lead is moved to the "Negotiation" stage
- THEN the aging counter MUST reset to "0d in stage"
- AND the previous stage duration SHOULD be preserved in the activity timeline

---

### Requirement: Lead Import/Export CSV [V1]

The system MUST support importing leads from CSV and exporting leads to CSV for migration and reporting.

#### Scenario: Export current lead list to CSV

- GIVEN a filtered lead list showing 8 leads
- WHEN the user clicks "Export CSV"
- THEN the system MUST generate a CSV file containing all displayed leads
- AND the CSV MUST include columns: title, description, value, currency, probability, expectedCloseDate, source, priority, stage, assignee, client, category
- AND the file MUST be downloaded to the user's browser

#### Scenario: Import leads from CSV

- GIVEN a CSV file with columns: title, value, source, priority
- WHEN the user uploads the CSV via the import function
- THEN the system MUST create lead objects for each valid row
- AND leads MUST be placed on the default pipeline's first stage
- AND a summary MUST be shown: "Imported 12 leads. 3 rows skipped (missing title)."

#### Scenario: Import with validation errors

- GIVEN a CSV file where row 3 has value "-500" and row 7 has no title
- WHEN the user imports the file
- THEN the system MUST skip invalid rows
- AND the summary MUST list specific errors: "Row 3: Value must be non-negative. Row 7: Title is required."
- AND valid rows MUST still be imported

---

### Requirement: Error Scenarios [MVP]

The system MUST handle error conditions gracefully and provide meaningful feedback to users.

#### Scenario: Create lead when OpenRegister is unavailable

- GIVEN the OpenRegister API is unreachable
- WHEN the user attempts to create a lead
- THEN the system MUST display an error message: "Could not save lead. Please try again later."
- AND the form data MUST be preserved so the user does not lose their input

#### Scenario: Move lead to non-existent stage

- GIVEN a lead on a pipeline
- WHEN a stage is deleted by an admin while a user is viewing the kanban
- AND the user attempts to drag a lead to the deleted stage
- THEN the system MUST display an error: "Stage no longer exists. Please refresh the page."
- AND the lead MUST remain in its current stage

#### Scenario: Edit lead concurrently modified by another user

- GIVEN two users editing the same lead simultaneously
- WHEN user A saves changes after user B has already saved
- THEN the system SHOULD detect the conflict (via OpenRegister versioning)
- AND display a message: "This lead was modified by another user. Please reload and try again."

---

## ADDED Requirements

### Requirement: Lead Capture from External Sources [V1]

The system SHOULD support creating leads from external channels beyond manual entry. This includes web form submissions, email parsing, and integration with the prospect discovery module. External lead capture reduces data entry and ensures no potential opportunity is missed.

#### Scenario: Create lead from prospect discovery widget

- GIVEN the prospect discovery widget displays a prospect "TechBedrijf BV" (KVK: 12345678, SBI: 62 - IT-dienstverlening, fitScore: 82%, city: Amsterdam)
- WHEN the user clicks "Create Lead" on the prospect card
- THEN the system MUST call the `/api/prospects/create-lead` endpoint with the prospect data
- AND a new lead MUST be created with `title` set to the prospect's trade name ("TechBedrijf BV")
- AND the lead's `source` MUST be set to "prospect_discovery"
- AND if the prospect has a matching client in the system (by KVK number), the lead MUST be auto-linked to that client
- AND the lead MUST be placed on the default pipeline's first non-closed stage
- AND a success notification MUST be displayed: "Lead created from prospect: TechBedrijf BV"

#### Scenario: Create lead via public web form API

- GIVEN an admin has configured a lead capture endpoint with allowed fields (title, description, contactEmail, contactName, source)
- WHEN an external system POSTs to `/api/public/lead-capture/{apiKey}` with valid data
- THEN the system MUST create a new lead with `source` set to "website"
- AND the lead MUST be assigned to the configured default assignee (if set)
- AND the lead MUST appear in the lead list and on the pipeline kanban
- AND the system MUST return HTTP 201 with the created lead's ID

#### Scenario: Reject lead capture with invalid API key

- GIVEN a public lead capture endpoint
- WHEN an external system POSTs to `/api/public/lead-capture/{invalidKey}`
- THEN the system MUST return HTTP 401 Unauthorized
- AND no lead MUST be created
- AND the system SHOULD log the failed attempt for security monitoring

#### Scenario: Create lead from inbound email

- GIVEN n8n workflow configured with an email-to-lead trigger
- WHEN a new email arrives at the configured inbox matching lead capture rules
- THEN the n8n workflow MUST extract sender name, email, subject, and body
- AND create a lead via the OpenRegister API with `title` set to the email subject
- AND `source` set to "email"
- AND `description` set to the email body (first 2000 characters)
- AND if a contact with the sender's email exists, the lead MUST be linked to that contact

---

### Requirement: Lead Qualification Scoring [V1]

The system SHOULD support scoring leads based on configurable qualification criteria to help sales teams prioritize effort. Scoring provides an objective measure complementing the subjective pipeline stage progression.

#### Scenario: Configure scoring criteria in admin settings

- GIVEN the admin navigates to Pipelinq settings
- WHEN they open the "Lead Scoring" section
- THEN the system MUST display configurable scoring criteria:
  - Value present: +10 points
  - Value above threshold (configurable, default EUR 10,000): +20 points
  - Client linked: +15 points
  - Contact linked: +10 points
  - Source is "referral" or "partner": +15 points
  - Expected close date within 30 days: +10 points
  - Priority "high" or "urgent": +10 points
  - Has description: +5 points
  - Has products/line items: +5 points
- AND the admin MUST be able to enable/disable individual criteria
- AND the admin MUST be able to adjust point values

#### Scenario: Auto-calculate qualification score on lead save

- GIVEN a lead "Gemeente XYZ digitalisering" with value EUR 50,000, linked client, source "referral", priority "high", expectedCloseDate in 15 days
- WHEN the lead is saved or updated
- THEN the system MUST calculate `qualificationScore` based on all enabled criteria
- AND the score for this lead SHOULD be: 10 (value present) + 20 (above threshold) + 15 (client linked) + 15 (source referral) + 10 (close date within 30 days) + 10 (priority high) = 80
- AND the score MUST be stored on the lead object as `qualificationScore`

#### Scenario: Display qualification score on lead list and detail

- GIVEN a lead with qualificationScore 80
- WHEN the lead is displayed in the lead list or detail view
- THEN the system MUST display the score as a badge or meter (e.g., "80/100")
- AND scores above 70 SHOULD be visually highlighted as "hot" (e.g., green/warm color)
- AND scores between 40-70 SHOULD be shown as "warm"
- AND scores below 40 SHOULD be shown as "cold"

#### Scenario: Sort leads by qualification score

- GIVEN multiple leads with different qualification scores
- WHEN the user sorts the lead list by "Score" descending
- THEN leads MUST be ordered by `qualificationScore` from highest to lowest
- AND leads without a score SHOULD appear last

---

### Requirement: Lead-to-Client Conversion [V1]

The system SHOULD support converting a lead into a client record when the lead reaches a sufficient qualification stage. Unlike EspoCRM's atomic conversion that creates separate Account + Contact + Opportunity, Pipelinq's unified model keeps the lead as the deal record and promotes the associated entity to a full client.

#### Scenario: Convert lead with no existing client

- GIVEN a lead "Acme Corp Infrastructure Upgrade" in stage "Qualified" with no linked client
- AND the lead has contact name "Petra Jansen", email "petra@acme.nl"
- WHEN the user clicks "Create Client" on the lead detail view
- THEN the system MUST display a pre-filled client creation form with data extracted from the lead (title from lead description, contact information)
- AND upon saving, the new client MUST be created as an OpenRegister object
- AND the lead MUST be automatically linked to the new client via the `client` reference
- AND a contact person record SHOULD be created and linked to both the client and the lead

#### Scenario: Link lead to existing client via search

- GIVEN a lead "Website Redesign" with no linked client
- WHEN the user clicks "Link Client" and searches for "Gemeente Utrecht"
- THEN the system MUST search existing client objects by name
- AND display matching results with organization name, email, and phone
- AND selecting a client MUST update the lead's `client` reference
- AND the lead MUST appear on the client's detail view under "Leads"

#### Scenario: Bulk convert leads to clients

- GIVEN 5 selected leads in stage "Qualified" with no linked clients
- WHEN the user selects "Create Clients" from the bulk actions menu
- THEN the system MUST create a client for each lead that does not already have one
- AND link each lead to its newly created client
- AND display a summary: "Created 5 clients from 5 leads"
- AND leads that already have a linked client MUST be skipped with a note

---

### Requirement: Lead Assignment Rules [V1]

The system SHOULD support automated lead assignment based on configurable rules to distribute incoming leads fairly across the sales team.

#### Scenario: Configure round-robin assignment

- GIVEN the admin navigates to Pipelinq settings -> "Assignment Rules"
- WHEN they enable round-robin assignment and select users "jan", "maria", "pieter"
- THEN the configuration MUST be stored via IAppConfig
- AND new leads created without an explicit assignee MUST be assigned to the next user in the rotation
- AND the rotation MUST cycle: jan -> maria -> pieter -> jan -> ...

#### Scenario: Round-robin assignment on lead creation

- GIVEN round-robin is enabled with users ["jan", "maria", "pieter"] and the last assigned user was "jan"
- WHEN a new lead is created (via form, API, or prospect conversion) without specifying an assignee
- THEN the system MUST assign the lead to "maria" (next in rotation)
- AND the next lead MUST be assigned to "pieter"
- AND if "maria" is disabled or deleted from Nextcloud, the system MUST skip to "pieter"

#### Scenario: Manual assignment overrides round-robin

- GIVEN round-robin is enabled
- WHEN a user explicitly selects an assignee during lead creation
- THEN the manual selection MUST take precedence over round-robin
- AND the round-robin counter MUST NOT advance (the explicit choice does not consume a rotation slot)

#### Scenario: Assignment based on lead source

- GIVEN the admin has configured source-based assignment rules:
  - source "website" -> assign to "jan"
  - source "referral" -> assign to "maria"
  - all others -> round-robin
- WHEN a new lead is created with source "referral" and no explicit assignee
- THEN the lead MUST be assigned to "maria"
- AND the round-robin counter MUST NOT advance

---

### Requirement: Lead Deduplication [V1]

The system SHOULD detect and help resolve duplicate leads to maintain data quality. Deduplication checks during creation and provides a merge interface for existing duplicates.

#### Scenario: Warn on potential duplicate during creation

- GIVEN an existing lead titled "Gemeente Utrecht Website Redesign" linked to client "Gemeente Utrecht"
- WHEN a user creates a new lead with title "Website Redesign Gemeente Utrecht"
- THEN the system MUST perform a fuzzy match against existing leads by title and client
- AND if a potential duplicate is found (similarity score > 70%), display a warning: "Possible duplicate: 'Gemeente Utrecht Website Redesign' (stage: Qualified, value: EUR 25,000)"
- AND the user MUST be able to dismiss the warning and create the lead anyway
- AND the user MUST be able to click "View existing" to navigate to the potential duplicate

#### Scenario: Detect duplicate by client and similar value

- GIVEN an existing lead for client "Acme Corp" with value EUR 50,000, source "website"
- WHEN a user creates a new lead for the same client "Acme Corp" with value EUR 50,000
- THEN the system MUST flag it as a high-confidence duplicate (same client + similar value)
- AND the warning MUST be more prominent than a title-only match

#### Scenario: Merge two duplicate leads

- GIVEN two leads:
  - Lead A: "Acme Digitalization" (value: EUR 25,000, source: "website", stage: "Contacted", notes: 3)
  - Lead B: "Acme Digital Transformation" (value: EUR 30,000, source: "referral", stage: "Qualified", notes: 1)
- WHEN the user selects both leads and clicks "Merge"
- THEN the system MUST display a merge dialog showing all fields side by side
- AND the user MUST be able to choose which value to keep for each conflicting field
- AND upon confirmation, the primary lead MUST be updated with the selected values
- AND all notes from the secondary lead MUST be moved to the primary lead
- AND the secondary lead MUST be deleted
- AND the audit trail MUST record the merge with details of which lead was merged into which

---

### Requirement: Lead Tagging and Categorization [V1]

The system SHOULD support tagging leads with user-defined labels beyond the single `category` field to enable flexible grouping and filtering. Tags use the same TagManager pattern as lead sources.

#### Scenario: Add tags to a lead

- GIVEN a lead "Gemeente ABC deal" with no tags
- WHEN the user adds tags "government", "digital-transformation", "Q2-2026"
- THEN the lead MUST store `tags: ["government", "digital-transformation", "Q2-2026"]`
- AND the tags MUST be displayed on the lead detail view as clickable chips
- AND the tags MUST be displayed on the kanban card (if configured in card settings)

#### Scenario: Filter leads by tag

- GIVEN 10 leads: 4 tagged "government", 3 tagged "enterprise", 2 tagged both "government" and "enterprise", 1 untagged
- WHEN the user filters the lead list by tag "government"
- THEN exactly 4 leads MUST be shown (including the 2 that also have "enterprise")
- AND the user SHOULD be able to combine tag filters with other filters (source, stage, assignee)

#### Scenario: Manage lead tags in admin settings

- GIVEN the admin navigates to Pipelinq settings
- WHEN they open the "Lead Tags" section
- THEN the system MUST display a TagManager interface (same pattern as lead sources)
- AND the admin MUST be able to add, rename, and remove tags
- AND removing a tag MUST remove it from all leads that use it
- AND the admin SHOULD be warned before removing a tag used by existing leads

#### Scenario: Auto-tag leads based on source

- GIVEN the admin has configured auto-tagging rules:
  - source "website" -> tag "inbound"
  - source "referral" -> tag "warm-intro"
  - source "campaign" -> tag "marketing"
- WHEN a new lead is created with source "referral"
- THEN the system MUST automatically add the tag "warm-intro" to the lead
- AND the user MUST be able to add additional tags or remove the auto-tag

---

### Requirement: Lead Nurturing Workflow [Enterprise]

The system MUST support automated nurturing workflows that trigger actions based on lead stage, age, or score. Nurturing workflows are implemented as n8n workflows triggered by OpenRegister object events.

#### Scenario: Configure stage-based follow-up reminders

- GIVEN an n8n workflow configured to listen for lead stage changes
- WHEN a lead enters the "Contacted" stage
- THEN the workflow MUST schedule a follow-up reminder for the assigned user after 3 days (configurable)
- AND the reminder MUST appear as a Nextcloud notification
- AND if the lead moves to a different stage before the reminder triggers, the reminder MUST be cancelled

#### Scenario: Nurture stale leads with automated notifications

- GIVEN an n8n workflow configured with a stale lead trigger (threshold: 14 days)
- WHEN a lead has had no activity for 14 days and is in a non-closed stage
- THEN the workflow MUST send a notification to the assigned user: "Lead '{title}' has been inactive for 14 days"
- AND the workflow SHOULD offer quick actions in the notification: "Add Note", "View Lead", "Mark as Lost"

#### Scenario: Escalate high-value stale leads

- GIVEN an n8n workflow configured for escalation
- WHEN a lead with value above EUR 50,000 has been stale for more than 7 days
- THEN the workflow MUST notify the lead's assignee AND the configured sales manager
- AND the notification MUST include the lead value, stage, and days since last activity
- AND the lead SHOULD be automatically re-prioritized to "high" if currently "normal" or "low"

---

### Requirement: Lead Reporting and Analytics [V1]

The system SHOULD provide reporting and analytics for lead management performance, pipeline health, and conversion metrics. Reports complement the existing dashboard KPIs with deeper drill-down capabilities.

#### Scenario: Pipeline value summary by stage

- GIVEN 20 leads distributed across pipeline stages with various values
- WHEN the user views the pipeline dashboard
- THEN the system MUST display total value per stage (e.g., New: EUR 45,000, Contacted: EUR 80,000, Qualified: EUR 120,000)
- AND the system MUST display weighted value per stage (value * probability for each lead, summed)
- AND the system MUST display the total pipeline value (sum of all open leads)
- AND the system MUST display the weighted pipeline value (sum of all weighted values)

#### Scenario: Lead conversion rate by source

- GIVEN leads from multiple sources over a configurable date range
- WHEN the user views the "Source Performance" report
- THEN the system MUST display for each source:
  - Total leads created
  - Leads converted (reached Won stage)
  - Conversion rate (won / total * 100)
  - Average deal value of won leads
  - Average time to close (days from creation to Won stage)
- AND sources MUST be sorted by conversion rate descending

#### Scenario: Lead aging report

- GIVEN leads in various pipeline stages
- WHEN the user views the "Lead Aging" report
- THEN the system MUST display a breakdown of leads by days in current stage:
  - 0-7 days: on track
  - 8-14 days: attention needed
  - 15-30 days: at risk
  - 30+ days: stale
- AND each category MUST show lead count and total value
- AND clicking a category MUST filter the lead list to show those leads

#### Scenario: Won/lost analysis

- GIVEN closed leads (won and lost) over the past 12 months
- WHEN the user views the "Win/Loss Analysis" report
- THEN the system MUST display:
  - Total won vs lost count and ratio
  - Average deal size for won vs lost leads
  - Most common "lost" stage (where leads were before moving to Lost)
  - Win rate trend over time (monthly)
- AND the report MUST support filtering by source, assignee, and date range

---

### Requirement: Lead Products and Line Items [MVP]

The system MUST support attaching products as line items to leads to detail the commercial offering. Line items allow granular value tracking and generate the lead's total value from individual product entries.

#### Scenario: Add product line item to a lead

- GIVEN a lead "Server Upgrade Project" and a product "Cloud Server License" (unit price: EUR 500)
- WHEN the user opens the LeadProducts component and clicks "Add Product"
- THEN the system MUST display a product search/selector
- AND upon selecting "Cloud Server License", a line item MUST be added with:
  - product reference
  - quantity: 1 (default)
  - unitPrice: EUR 500 (from product)
  - discount: 0%
  - lineTotal: EUR 500

#### Scenario: Calculate lead value from line items

- GIVEN a lead with line items:
  - Cloud Server License: qty 10, unitPrice EUR 500, discount 10% -> lineTotal EUR 4,500
  - Setup Fee: qty 1, unitPrice EUR 1,000, discount 0% -> lineTotal EUR 1,000
- WHEN the user saves the lead
- THEN the system SHOULD offer to update the lead's `value` field to the sum of line totals (EUR 5,500)
- AND if auto-sync is enabled, the `value` field MUST be automatically updated
- AND the lead detail MUST show both the individual line items and the total value

#### Scenario: Remove product line item

- GIVEN a lead with 3 line items totaling EUR 10,000
- WHEN the user removes one line item worth EUR 3,000
- THEN the lead MUST update to show 2 line items totaling EUR 7,000
- AND if value auto-sync is enabled, the lead's `value` MUST update to EUR 7,000

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

---

### Current Implementation Status

**Substantially implemented.** Core lead CRUD, list view, detail view, pipeline stage lifecycle, source tracking, assignment, value/probability, priority, and kanban board are all functional. V1 features (stale detection, aging indicator, import/export) are NOT implemented.

Implemented:
- **Data model**: `lib/Settings/pipelinq_register.json` defines the `lead` schema with: `title` (required), `client`, `contact`, `source`, `value`, `probability` (0-100), `expectedCloseDate`, `assignee`, `priority` (low/normal/high/urgent), `pipeline`, `stage`, `stageOrder`, `notes`, `status` (open/won/lost). Mapped to `schema:Demand`.
- **Lead CRUD**: Handled via the generic object store (`src/store/modules/object.js`) calling OpenRegister's object API. No dedicated lead controller -- all CRUD goes through OpenRegister.
- **Lead List View**: `src/views/leads/LeadList.vue` -- table with columns for title, value, stage, priority, source, assignee, expected close date. Supports search, sort, filter, and pagination via OpenRegister's query API.
- **Lead Detail View**: `src/views/leads/LeadDetail.vue` -- displays core info (value formatted as EUR, probability as %, source, priority, expected close date, category), pipeline progress indicator, client/contact links, assignee section, activity timeline via sidebar. Edit/delete actions with confirmation dialog.
- **Lead Form**: `src/views/leads/LeadForm.vue` -- create/edit form with all lead fields.
- **Lead Create Dialog**: `src/views/leads/LeadCreateDialog.vue` -- quick-create dialog used from dashboard.
- **Pipeline Board (Kanban)**: `src/views/pipeline/PipelineBoard.vue` -- kanban board with columns per stage, drag-and-drop for stage changes. `PipelineCard.vue` -- card with title, value, priority badge, due date, assignee. `PipelineSidebar.vue` -- sidebar for the pipeline view.
- **Lead Source Tracking**: `source` field on lead with configurable values managed by `LeadSourceController` and `TagManager` in admin settings. Default sources: website, email, phone, referral, partner, campaign, social_media, event, other.
- **Lead Assignment**: `assignee` field stores Nextcloud user UID. Activity events published on assignment change. Leads appear in My Work view when assigned to current user.
- **Lead Priority**: Four levels (low, normal, high, urgent) with visual badges on kanban cards.
- **Pipeline Stage Lifecycle**: Moving leads between stages via kanban drag-and-drop or detail view. Stage changes trigger activity events (`publishStageChanged`). Closed stages (isClosed/isWon) handled in dashboard KPI calculations.
- **Lead Value/Probability**: Value displayed with EUR formatting. Probability stored as 0-100 integer.
- **Expected Close Date**: Stored as date string. Overdue detection in dashboard (leads past close date in non-closed stages).
- **Event handling**: `ObjectEventListener` detects lead creation, assignment changes, and stage changes, publishing to Activity stream and sending notifications.
- **Lead Products**: `src/components/LeadProducts.vue` -- line items linking products to leads with quantity, unit price, discount, and computed total.
- **Prospect-to-Lead conversion**: `src/store/modules/prospect.js` -- `createLeadFromProspect()` action calls `/api/prospects/create-lead` to convert prospect discovery results into leads.
- **Routing**: `/leads` (list), `/leads/:id` (detail), `/pipeline` (kanban board).

NOT implemented:
- **Lead capture from external sources** (REQ-LEAD-CAPTURE) -- no public API endpoint for web form lead capture; prospect-to-lead exists but no email-to-lead workflow.
- **Lead qualification scoring** (REQ-LEAD-SCORING) -- no scoring criteria configuration or auto-calculation.
- **Lead-to-client conversion** (REQ-LEAD-CONVERSION) -- client can be linked manually but no pre-filled conversion wizard or bulk conversion.
- **Lead assignment rules** (REQ-LEAD-ASSIGNMENT-RULES) -- manual assignment only; no round-robin or source-based rules.
- **Lead deduplication** (REQ-LEAD-DEDUP) -- no duplicate detection during creation or merge interface.
- **Lead tagging** (REQ-LEAD-TAGS) -- single `category` field exists but no multi-tag system with TagManager.
- **Lead nurturing workflows** (REQ-LEAD-NURTURE) -- no n8n workflow templates for follow-up reminders or stale escalation.
- **Lead reporting/analytics** (REQ-LEAD-REPORTING) -- dashboard has basic KPIs but no drill-down reports for source performance, aging, or win/loss analysis.
- **Lead value auto-calculation from line items** -- LeadProducts component exists but does not automatically update the lead's `value` field from line item totals.
- **Quick actions on kanban cards** (REQ-LEAD-011) -- no right-click context menu or action menu on kanban cards for quick move/assign/priority changes.
- **Stale lead detection** (REQ-LEAD-012, V1) -- no staleness threshold configuration, no "days since last activity" calculation.
- **Aging indicator** (REQ-LEAD-013, V1) -- no "days in current stage" display on kanban cards or detail view.
- **Lead import/export CSV** (REQ-LEAD-014, V1) -- no import or export functionality.
- **Validation** -- basic validation exists in the schema (required title, value minimum 0, probability 0-100), but client-side inline validation (REQ-LEAD-002) with highlighted invalid fields may be incomplete. No backend validation controller for Pipelinq-specific rules.
- **Concurrent edit conflict detection** (Scenario 57) -- relies on OpenRegister's versioning but no UI for conflict resolution.
- **Overdue indicator on lead list/detail** -- overdue detection works on the dashboard but individual lead views may not show the "X days overdue" indicator.

### Standards & References
- Schema.org `Demand` -- lead entity mapping
- ISO 4217 -- currency codes (EUR default)
- CRM industry patterns (HubSpot, Salesforce, EspoCRM) -- pipeline stage lifecycle, kanban board UX
- OpenRegister Object API -- all CRUD operations
- Nextcloud Activity API -- event publishing for lead lifecycle events
- WCAG AA -- accessible form labels and keyboard navigation

### Specificity Assessment
- The spec is highly detailed with 57+ base scenarios plus ADDED requirements covering lead capture, scoring, conversion, assignment rules, deduplication, tagging, nurturing, reporting, and line items.
- **Mostly implementable as-is** -- MVP requirements are largely complete. Remaining work is V1/Enterprise features and polish.
- **Gap**: The spec uses `assignedTo` as the field name but the implementation uses `assignee`. This naming discrepancy should be resolved.
- **Gap**: The spec says leads should be placed on the "first non-closed stage" of the default pipeline on creation, but this default assignment logic may not be implemented in the frontend form.
- **Gap**: The spec references `currency` as a separate field (ISO 4217), but the schema does not include a `currency` property -- EUR is assumed/hardcoded in the UI.
- **Open question**: Should lead validation happen in a Pipelinq validation service or rely entirely on OpenRegister's JSON Schema validation?
- **Open question**: Should lead scoring be computed on the frontend (Vue computed property) or backend (n8n workflow / PHP service)?
