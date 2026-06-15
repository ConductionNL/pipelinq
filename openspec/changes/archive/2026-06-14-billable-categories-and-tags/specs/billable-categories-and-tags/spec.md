---
status: draft
---

# Spec: Billable / Non-billable / Internal / WBSO / DBA Tags

## Purpose

Define the requirements for structured billing classification of time entries in pipelinq. This spec covers billing category lifecycle management, time entry category assignment, WBSO compliance enforcement, category-based report filtering, and default category behavior.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 20/26 competitors
**Depends on**: `time-entry-core` (provides `timeEntry` entity, list view, and create/edit dialog)

---

## REQ-BCT-001: Billing category lifecycle management

The system MUST allow administrators to create, view, edit, and deactivate billing categories. Each category MUST have a unique code, a display name, a type (billable / non-billable / internal), and optional Dutch compliance flags.

### Scenario: Create a billing category

- GIVEN the administrator opens the billing categories list at `/billing-categories`
- WHEN the administrator clicks "Nieuwe categorie" and submits the form with name "Declarabel", code "BILL", type "billable", color "#28a745"
- THEN a new `billingCategory` object MUST be created in OpenRegister with the submitted values
- AND the new category MUST appear in the list view immediately

### Scenario: Category code must be unique

- GIVEN a `billingCategory` with code "BILL" already exists
- WHEN an administrator attempts to create a second category with code "BILL"
- THEN the system MUST reject the submission with a validation error
- AND the error message MUST read `t('pipelinq', 'Category code {code} is already in use', { code: 'BILL' })`

### Scenario: Deactivate a billing category

- GIVEN a `billingCategory` with `isActive: true` exists
- WHEN the administrator sets `isActive` to false and saves
- THEN the category MUST no longer appear in the billing category picker on time entry forms
- AND existing time entries already assigned to this category MUST retain their assignment (no cascade change)
- AND the deactivated category MUST remain visible in the category management list with an "Inactief" badge

### Scenario: Category list is sorted by type then name

- GIVEN multiple billing categories exist with mixed types
- WHEN the administrator views the billing categories list
- THEN categories MUST be grouped by type in the order: billable → non-billable → internal
- AND within each group, categories MUST be sorted alphabetically by name

---

## REQ-BCT-002: Time entry billing category assignment

When creating or editing a time entry, the user MUST be able to assign a billing category. The default billing category MUST be pre-selected automatically.

### Scenario: Default category pre-selected on new time entry

- GIVEN a `billingCategory` with `isDefault: true` and `isActive: true` exists
- WHEN a user opens the time entry create dialog
- THEN the `billingCategory` field MUST be pre-populated with the default category
- AND the user MAY change it to any other active category

### Scenario: No default category defined

- GIVEN no `billingCategory` has `isDefault: true`
- WHEN a user opens the time entry create dialog
- THEN the `billingCategory` field MUST be empty (null)
- AND the user MAY select a category before saving

### Scenario: Only one category can be the default

- GIVEN `billingCategory` A has `isDefault: true`
- WHEN an administrator sets `billingCategory` B's `isDefault` to true
- THEN `billingCategory` A's `isDefault` MUST automatically be set to false
- AND only one `billingCategory` MAY have `isDefault: true` at any time

### Scenario: Saving a time entry without a billing category

- GIVEN the `billingCategory` field is empty on a time entry form
- WHEN the user saves the time entry
- THEN the time entry MUST be saved with `billingCategory: null`
- AND the entry MUST appear as "Zonder categorie" in reports
- AND NO validation error MUST be shown (billing category is optional)

### Scenario: Billing category displayed in time entry detail

- GIVEN a time entry has `billingCategory` referencing "Declarabel" (code "BILL", color "#28a745")
- WHEN the user views the time entry detail
- THEN the billing category name "Declarabel" MUST be displayed with a green color badge
- AND the type label "Declarabel" MUST appear next to the category name

---

## REQ-BCT-003: WBSO category compliance

When a billing category has `requiresWbsoRef: true`, time entries assigned to that category MUST prompt the user to supply a WBSO project reference. This supports WBSO hour administration for RVO submissions.

### Scenario: WBSO reference prompt on category selection

- GIVEN a `billingCategory` with `requiresWbsoRef: true` (e.g., "WBSO O&O") is selected on a time entry form
- WHEN the user selects this category
- THEN a notice MUST appear: `t('pipelinq', 'WBSO reference required')`
- AND a text field for the WBSO project reference MUST become visible in the form

### Scenario: WBSO reference saved with time entry

- GIVEN a user fills in WBSO project reference "WBSO2026-12345" on a time entry
- WHEN the user saves the time entry
- THEN the WBSO reference MUST be stored in the time entry's `notes` or a dedicated `wbsoRef` field
- AND the reference MUST be retrievable in the time entry detail view

### Scenario: Non-WBSO category hides the reference field

- GIVEN a `billingCategory` with `requiresWbsoRef: false` is selected
- WHEN the user selects this category
- THEN the WBSO project reference field MUST NOT be visible
- AND no WBSO-related notice MUST be displayed

### Scenario: WBSO hours aggregated in report

- GIVEN multiple time entries are assigned to a category with `requiresWbsoRef: true`
- WHEN the administrator views the billing category dashboard widget
- THEN hours for WBSO categories MUST be visually distinguished (distinct color, "WBSO" badge)
- AND total WBSO hours MUST be summed and displayed separately from other non-billable hours

---

## REQ-BCT-004: Category-based report filtering

The time entry list view MUST support filtering by billing category using `CnFacetSidebar`. The dashboard MUST include a widget showing hours distributed across billing categories.

### Scenario: Filter time entries by billing category

- GIVEN the time entry list view is open
- WHEN the user clicks "Declarabel" in the `CnFacetSidebar` billing category facet
- THEN the list MUST show only time entries where `billingCategory` references a category of type "billable" or with code "BILL"
- AND the selected facet MUST remain active across page navigation (persisted in URL query params)

### Scenario: Multiple category filters applied

- GIVEN the time entry list view is open
- WHEN the user selects both "Declarabel" and "DBA Opdracht" in the facet sidebar
- THEN the list MUST show time entries assigned to either category
- AND the result count MUST be displayed in the facet sidebar next to each category name

### Scenario: Filter by uncategorized time entries

- GIVEN some time entries have `billingCategory: null`
- WHEN the user selects "Zonder categorie" in the facet sidebar
- THEN only time entries with no billing category MUST be shown

### Scenario: Dashboard widget shows hours per category

- GIVEN time entries exist across multiple billing categories
- WHEN the user views the dashboard with the billing category widget active
- THEN the `BillingCategoryWidget` MUST render a donut chart with one segment per category
- AND each segment MUST use the category's `color` value
- AND the total hours label MUST appear in the center of the donut
- AND all labels MUST use the Dutch category `name` (not the code)

### Scenario: Widget segment click filters time entry list

- GIVEN the billing category dashboard widget is visible
- WHEN the user clicks the "WBSO O&O" segment of the donut chart
- THEN the browser MUST navigate to the time entry list filtered by `billingCategory = WBSO`
- AND the facet sidebar MUST reflect the active filter

---

## REQ-BCT-005: DBA contractor classification

Billing categories with `isDba: true` MUST be distinguishable in reports to support Wet DBA tax compliance for Dutch organizations that engage ZZP contractors.

### Scenario: DBA category badge in time entry list

- GIVEN a time entry is assigned a `billingCategory` with `isDba: true` (e.g., "DBA Opdracht")
- WHEN the time entry appears in the list view
- THEN a "DBA" badge MUST be displayed next to the category name in the row
- AND the badge color MUST match the category's `color` field

### Scenario: DBA hours aggregated separately

- GIVEN multiple time entries are assigned to DBA categories
- WHEN the billing category widget renders
- THEN DBA category segments MUST be displayed separately from non-DBA billable segments
- AND DBA total hours MUST be labeled "DBA Opdracht" in Dutch in the chart legend

### Scenario: DBA category selectable like any other

- GIVEN a `billingCategory` with `isDba: true` and `isActive: true` exists
- WHEN the user opens the time entry create/edit dialog
- THEN the DBA category MUST appear in the billing category dropdown alongside all other active categories
- AND selecting it MUST NOT trigger any blocking validation (DBA is informational, not a hard constraint)
