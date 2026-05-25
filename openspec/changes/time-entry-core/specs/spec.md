---
status: draft
---

# Time Entry Core Specification

## Purpose

The Time Entry Core module enables Pipelinq users to log time against their work in three modes: an active start/stop timer, manual (retrospective) entry, and a weekly timesheet grid. Entries can be linked to clients and leads for billable hour tracking. A free-text comment field and an edit window are available on every entry.

**Standards**: Schema.org (`schema:Action`, `schema:Duration`), ISO 8601 duration notation
**Feature tier**: P0-must (MVP)
**Competitor coverage**: 22/26 analysed competitors implement at least one of these entry modes

## Data Model

Time entries are stored as OpenRegister objects in the `pipelinq` register:

- **timeEntry**: `date` (date), `startTime` (date-time), `endTime` (date-time), `duration` (integer, minutes), `description` (string), `comment` (string), `entryType` (enum: timer/manual), `billable` (boolean), `userId` (Nextcloud UID), `client` (UUID ref → client), `lead` (UUID ref → lead)

OpenRegister built-ins provide: id, uuid, createdAt, updatedAt, owner, status, auditTrail, tags.

---

## REQ-TIME-001: Start/Stop Timer

The system MUST provide a one-click timer that records the start and end timestamps of an active work session and automatically computes the duration.

**Feature tier**: P0-must

### Scenario: Start a timer

- GIVEN a Pipelinq user is logged in and no active timer is running
- WHEN the user clicks the Start button in the timer widget
- THEN the system MUST create a `timeEntry` object with `entryType = timer`, `startTime = current UTC timestamp`, `date = today`, `status = active`
- AND the widget MUST display a running elapsed time counter in `HH:MM:SS` format
- AND the Start button MUST change to a Stop button

### Scenario: Only one active timer per user

- GIVEN a Pipelinq user already has an active timer running
- WHEN the user attempts to start another timer
- THEN the system MUST display a message "Er loopt al een timer. Stop de huidige timer eerst."
- AND no second active `timeEntry` object MUST be created

### Scenario: Stop the active timer

- GIVEN a Pipelinq user has an active timer that was started at `startTime`
- WHEN the user clicks the Stop button
- THEN the system MUST set `endTime = current UTC timestamp` on the active `timeEntry`
- AND the system MUST compute `duration = (endTime - startTime) in whole minutes` and store it
- AND the system MUST set `status = complete`
- AND the system MUST present the completed entry in the manual entry review dialog pre-filled with the timer data so the user can add description, comment, and client/lead link before saving

### Scenario: Timer persists across page navigation

- GIVEN a Pipelinq user starts a timer and navigates to another route within the app
- WHEN the new page loads
- THEN the timer widget MUST continue showing the elapsed time for the active timer
- AND the elapsed time MUST be accurate (computed from `startTime`, not reset by navigation)

### Scenario: Timer widget visible from all pages

- GIVEN a Pipelinq user is on any page within the app (dashboard, clients, leads, etc.)
- WHEN there is an active timer running
- THEN the timer widget in the app header MUST be visible and display the elapsed time
- AND the Stop button MUST be accessible without navigating to the time entries section

---

## REQ-TIME-002: Manual Time Entry

The system MUST allow users to log hours for past work sessions without using the timer, specifying date, duration, and optional metadata.

**Feature tier**: P0-must

### Scenario: Create a manual entry

- GIVEN a Pipelinq user wants to log 2 hours of work done yesterday
- WHEN the user opens the manual entry dialog and fills in: date = yesterday, duration = 2:00, description = "Ontwerp voorstel digitaal loket", billable = true
- THEN the system MUST create a `timeEntry` object with `entryType = manual`, the specified `date`, `duration = 120` (minutes), and `description`
- AND the entry MUST appear in the time entry list sorted by date descending

### Scenario: Duration input accepts HH:MM format

- GIVEN a user is filling in the manual entry form
- WHEN the user enters "1:30" in the duration field
- THEN the system MUST store `duration = 90` (minutes)
- AND the display MUST show "1u 30m" in list and grid views

### Scenario: Duration cannot be zero or negative

- GIVEN a user is filling in the manual entry form
- WHEN the user enters "0:00" or a negative value in the duration field
- THEN the system MUST display the validation error "Duur moet groter zijn dan 0 minuten"
- AND the form MUST NOT submit

### Scenario: Date defaults to today

- GIVEN a user opens the manual entry dialog
- WHEN the dialog loads
- THEN the date field MUST default to today's date
- AND the user MAY change it to any past date

### Scenario: Future dates are rejected

- GIVEN a user is filling in the manual entry form
- WHEN the user selects a future date (after today)
- THEN the system MUST display the validation error "Datum mag niet in de toekomst liggen"
- AND the form MUST NOT submit

---

## REQ-TIME-003: Weekly Timesheet Grid

The system MUST provide a spreadsheet-style weekly view showing all time entries across a selected ISO week, with per-day columns and weekly totals.

**Feature tier**: P0-must

### Scenario: View weekly grid for current week

- GIVEN a Pipelinq user navigates to /time-entries/weekly
- WHEN the view loads
- THEN the grid MUST display the current ISO week (Monday–Sunday) as column headers with dates
- AND all `timeEntry` objects for the current user in that week MUST appear as rows
- AND each row MUST show: description, and the duration placed in the correct day column
- AND the bottom row MUST show the total hours per day and a grand total for the week

### Scenario: Navigate between weeks

- GIVEN a user is viewing the weekly grid for week 21 of 2026
- WHEN the user clicks the "Vorige week" arrow
- THEN the grid MUST load week 20 entries
- AND the week label MUST update to "Week 20 — 11 mei t/m 17 mei 2026"

### Scenario: Add entry from weekly grid

- GIVEN a user is viewing the weekly grid
- WHEN the user clicks on an empty cell in the "Donderdag 14 mei" column
- THEN the manual entry dialog MUST open with `date` pre-filled to 2026-05-14
- AND after saving, the new entry MUST appear in the grid without a full page reload

### Scenario: Weekly total is correct

- GIVEN a user has logged 3 entries in a week: 90 min on Monday, 120 min on Wednesday, 45 min on Friday
- WHEN the user views the weekly grid for that week
- THEN the weekly total MUST show "4u 15m"
- AND each day column total MUST reflect only that day's entries

---

## REQ-TIME-004: Edit Window

The system MUST allow users to edit any of their own time entries through a full edit form accessible from both the list view and the weekly grid.

**Feature tier**: P0-must

### Scenario: Edit an existing entry

- GIVEN a time entry "Strategisch overleg" exists for the current user
- WHEN the user opens the entry and clicks Edit
- THEN an edit dialog MUST open pre-filled with all current field values
- AND the user MUST be able to change any editable field (date, duration, description, comment, billable, client, lead)
- AND after saving, the list and grid views MUST reflect the updated values

### Scenario: Edit is restricted to own entries

- GIVEN a time entry was created by a different Nextcloud user
- WHEN the current user views the entry
- THEN the Edit button MUST NOT be available
- AND the system MUST return HTTP 403 if the edit API endpoint is called for another user's entry

### Scenario: Edit window shows entry metadata

- GIVEN a user opens an existing time entry for editing
- WHEN the edit dialog loads
- THEN the dialog MUST display: created date, last modified date, entry type (timer/manual) as read-only metadata below the editable fields

### Scenario: Delete entry from edit window

- GIVEN a user has opened an existing entry
- WHEN the user clicks the Delete button and confirms via the confirmation dialog
- THEN the `timeEntry` object MUST be deleted from OpenRegister
- AND the user MUST be redirected back to the list view or weekly grid
- AND the deleted entry MUST no longer appear in any views

---

## REQ-TIME-005: Comment Per Entry

The system MUST provide a free-text comment field on each time entry that is distinct from the work description and visible in list and detail views.

**Feature tier**: P0-must

### Scenario: Add a comment to a time entry

- GIVEN a user is creating or editing a time entry
- WHEN the user types "Klant heeft goedkeuring gevraagd voor extra uren" in the comment field
- THEN the system MUST store the comment in the `comment` property of the `timeEntry` object
- AND the comment MUST be displayed in the entry detail view below the description

### Scenario: Comment is optional

- GIVEN a user creates a time entry without filling in the comment field
- THEN the system MUST save the entry with `comment = null` or empty string
- AND no validation error MUST be shown for the empty comment field

### Scenario: Comment is visible in list view

- GIVEN a time entry has a comment "Inclusief reistijd"
- WHEN the user views the time entry list
- THEN the comment MUST be shown as a secondary line below the description in the list row (truncated to 80 characters with ellipsis if longer)

### Scenario: Comment is editable independently

- GIVEN a time entry has the description "Klantgesprek" and comment "Duur: korter dan gepland"
- WHEN the user edits only the comment to "Duur: iets langer dan gepland, klant had extra vragen"
- THEN only the `comment` property MUST be updated on save
- AND the `description` MUST remain "Klantgesprek" unchanged

---

## REQ-TIME-006: Billable Flag

Each time entry MUST carry a billable flag indicating whether the time is chargeable to the client.

**Feature tier**: P0-must

### Scenario: New entry defaults to billable

- GIVEN a user opens the manual entry dialog or stops a timer
- WHEN the form loads
- THEN the billable toggle MUST default to ON (billable = true)

### Scenario: Mark entry as non-billable

- GIVEN a user is creating or editing a time entry
- WHEN the user sets billable = false
- THEN the system MUST store `billable = false` on the `timeEntry` object
- AND the entry MUST display a "Niet-factureerbaar" badge in list and detail views

### Scenario: Billable total on weekly grid

- GIVEN a user is viewing the weekly grid
- WHEN entries include both billable and non-billable entries
- THEN the weekly totals row MUST show billable hours separately from total hours (e.g., "4u 15m totaal / 3u 30m factureerbaar")

---

## REQ-TIME-007: Client and Lead Linking

Time entries MAY be linked to an existing `client` or `lead` object for billable hour attribution.

**Feature tier**: P0-must

### Scenario: Link entry to a client

- GIVEN a time entry is being created or edited
- WHEN the user selects "Gemeente Utrecht" from the client dropdown
- THEN the system MUST store the client's UUID in the `client` property of the `timeEntry`
- AND the client name MUST be displayed in the entry list and detail view

### Scenario: Link entry to a lead

- GIVEN a time entry is being created or edited
- WHEN the user selects a lead "Digitalisering archief Q3" from the lead selector
- THEN the system MUST store the lead's UUID in the `lead` property of the `timeEntry`
- AND the lead name MUST be displayed in the entry detail view

### Scenario: Unlink a client or lead

- GIVEN a time entry has `client` set to "Gemeente Rotterdam"
- WHEN the user clears the client field and saves
- THEN the system MUST store `client = null` on the `timeEntry`
- AND the client link MUST no longer appear in the entry views
