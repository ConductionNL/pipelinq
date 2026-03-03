# Lead CRUD — Delta Spec

## Purpose
Implement core lead CRUD operations, list view, detail view, and pipeline assignment. This is the MVP foundation for lead management.

**Main spec ref**: [lead-management/spec.md](../../../../specs/lead-management/spec.md)
**Feature tier**: MVP

---

## Requirements

### REQ-LC-001: Lead Create & Edit Form

The system MUST provide a form to create and edit leads with validation.

#### Scenario: Create a minimal lead

- GIVEN a user clicks "New lead" from the lead list
- WHEN they enter title "Website redesign" and submit
- THEN the lead MUST be created via `objectStore.saveObject('lead', data)`
- AND if a default pipeline exists for leads, the lead MUST be assigned to that pipeline's first non-closed stage
- AND the user MUST be navigated to the lead detail view

#### Scenario: Create a lead with full fields

- GIVEN the lead form is open
- WHEN the user enters title, description, value (25000), source ("referral"), priority ("high"), expectedCloseDate ("2026-06-01"), and selects a client and pipeline/stage
- THEN all fields MUST be stored on the lead object
- AND value MUST be stored as a number (not string)

#### Scenario: Edit an existing lead

- GIVEN the lead detail view for "Gemeente ABC deal"
- WHEN the user clicks Edit and changes the value from 25000 to 30000
- THEN the form MUST pre-populate all existing values
- AND saving MUST update the lead via `objectStore.saveObject('lead', data)` with the existing `id`
- AND the detail view MUST reflect the updated value

---

### REQ-LC-002: Lead Validation

The form MUST enforce validation rules before submission.

#### Scenario: Title is required

- GIVEN the lead form
- WHEN the user submits without a title
- THEN validation error "Title is required" MUST appear
- AND the Save button MUST be disabled when validation fails

#### Scenario: Value must be non-negative

- GIVEN the lead form
- WHEN the user enters value -5000
- THEN validation error "Value must be non-negative" MUST appear

#### Scenario: Probability must be 0-100

- GIVEN the lead form
- WHEN the user enters probability 150
- THEN validation error "Probability must be between 0 and 100" MUST appear

---

### REQ-LC-003: Lead List View

The system MUST provide a list view of all leads with search, filter, sort, and pagination.

#### Scenario: Display lead list with key columns

- GIVEN 15 leads exist
- WHEN the user navigates to the Leads section
- THEN a table MUST display columns: Title, Value, Stage, Priority, Source, Expected Close
- AND each row MUST be clickable to navigate to the lead detail view
- AND pagination MUST show 20 leads per page

#### Scenario: Search leads

- GIVEN leads titled "Gemeente ABC deal" and "TechCorp website"
- WHEN the user types "gemeente" in the search box
- THEN "Gemeente ABC deal" MUST appear in results
- AND "TechCorp website" MUST NOT appear
- AND search MUST be case-insensitive with 300ms debounce

#### Scenario: Filter by stage

- GIVEN leads in stages New (3), Qualified (4), Won (1)
- WHEN the user filters by stage "Qualified"
- THEN exactly 4 leads MUST be shown

#### Scenario: Filter by source

- GIVEN leads with sources: website (4), referral (3), phone (2)
- WHEN the user filters by source "referral"
- THEN exactly 3 leads MUST be shown

#### Scenario: Sort by value

- GIVEN leads with varying values
- WHEN the user clicks the Value column header
- THEN leads MUST be sorted by value (toggle asc/desc/none)

#### Scenario: Empty state

- GIVEN no leads exist
- WHEN the user navigates to the Leads section
- THEN an empty state MUST display with message and "Create first lead" button

---

### REQ-LC-004: Lead Detail View

The system MUST provide a detail view displaying all lead properties, pipeline progress, and linked entities.

#### Scenario: View lead core info

- GIVEN a lead "Acme Corp deal" with value 5000, probability 40, source "Website", priority "Normal", expectedCloseDate "2026-03-05"
- WHEN the user navigates to the lead detail
- THEN all properties MUST be displayed in a structured info grid
- AND value MUST be formatted as "EUR 5,000"
- AND probability MUST show as "40%"

#### Scenario: Pipeline progress indicator

- GIVEN a lead on "Sales Pipeline" in stage "Qualified" (3rd of 7 stages)
- WHEN the user views the lead detail
- THEN a pipeline progress indicator MUST show all stages
- AND completed stages (before current) MUST have filled indicators
- AND the current stage MUST be highlighted
- AND future stages MUST have empty indicators
- AND the pipeline name MUST be displayed

#### Scenario: Client and contact links

- GIVEN a lead linked to client "Acme Corporation" and contact "Petra Jansen"
- WHEN the user views the lead detail
- THEN the client name MUST be a clickable link to the client detail
- AND the contact name and role MUST be displayed
- AND if no client is linked, "No client linked" MUST be shown

#### Scenario: Delete lead

- GIVEN the lead detail view
- WHEN the user clicks Delete and confirms
- THEN the lead MUST be deleted via `objectStore.deleteObject('lead', id)`
- AND the user MUST be navigated back to the lead list

---

### REQ-LC-005: Lead Navigation & Routing

The app MUST integrate leads into the navigation and hash-based routing system.

#### Scenario: Leads menu item

- GIVEN the main navigation menu
- THEN a "Leads" item MUST appear between "Contacts" and "Requests"
- AND it MUST be highlighted when on the leads list or lead detail

#### Scenario: Hash routing

- GIVEN the URL hash is `#/leads`
- THEN the lead list view MUST render
- AND given `#/leads/{uuid}`
- THEN the lead detail view MUST render for that lead

---

### REQ-LC-006: Lead Pipeline Assignment

When creating or editing a lead, the user MUST be able to assign it to a pipeline and stage.

#### Scenario: Auto-assign to default pipeline on creation

- GIVEN a default pipeline "Sales Pipeline" exists for leads
- WHEN a user creates a lead without selecting a pipeline
- THEN the lead MUST be assigned to "Sales Pipeline"
- AND the lead MUST be placed on the first non-closed stage ("New")

#### Scenario: Manual pipeline and stage selection

- GIVEN two pipelines exist: "Sales Pipeline" and "Enterprise Pipeline"
- WHEN the user creates a lead and selects "Enterprise Pipeline" and stage "Qualified"
- THEN the lead MUST store the selected pipeline and stage references

#### Scenario: Stage selector filters by pipeline

- GIVEN the user has selected "Sales Pipeline" in the pipeline dropdown
- WHEN they open the stage dropdown
- THEN only stages belonging to "Sales Pipeline" MUST be shown
- AND changing the pipeline MUST reset the stage selection
