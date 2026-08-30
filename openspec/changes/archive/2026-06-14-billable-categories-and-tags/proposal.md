# Proposal: billable-categories-and-tags

## Problem

Time entries in pipelinq (via `time-entry-core`) carry no billing classification. Market intelligence covering 20/26 competitors shows that structured billing categorization is a universal expectation:

1. **No billing type distinction** — All time entries are treated identically. Organizations cannot distinguish billable client work from non-billable overhead, internal meetings, or training without parsing free-text notes manually. Competitors such as Harvest, Everhour, Toggl Track, and Tempo all provide a billable/non-billable toggle as a baseline feature.

2. **No WBSO compliance support** — Dutch R&D tax credits (WBSO) require that qualifying R&D hours are separately identified and reportable for RVO (Rijksdienst voor Ondernemend Nederland) submissions. Without a dedicated WBSO category, organizations cannot demonstrate compliance or generate WBSO hour reports — a critical gap for Dutch software companies and scale-ups.

3. **No DBA/ZZP classification** — Since the Wet DBA (Deregulering Beoordeling Arbeidsrelaties), Dutch organizations must distinguish self-employed contractor (ZZP) hours from employed staff hours. Time entries have no field for this distinction, creating tax and legal compliance risk for clients who hire ZZP workers.

4. **No internal overhead categorization** — Training, sick leave, internal meetings, and overhead time cannot be separated from client-billable hours in reports. This makes utilization analysis, budget tracking, and headcount planning impossible for managers and directors.

5. **No tag-based report filtering** — Without structured billing categories, dashboards and reports cannot aggregate time by billing type. This prevents accurate client billing reconciliation, profitability analysis, and capacity planning — all standard features across 20 of the 26 competitors surveyed.

## Solution

Implement a `billingCategory` entity as a structured taxonomy for time entry classification, with UI integration for category management, time entry tagging, and report filtering:

1. **`billingCategory` OpenRegister schema** — Structured category definitions with name, code, type (billable / non-billable / internal), display color, and Dutch-specific compliance flags (`requiresWbsoRef`, `isDba`). Pre-seeded with 5 standard Dutch billing categories covering the most common use cases out-of-the-box.

2. **Time entry category assignment** — Extend `timeEntry` (introduced by the `time-entry-core` dependency) with a `billingCategory` UUID reference. The category picker appears in the time entry create/edit dialog and pre-selects the default category.

3. **Category management views** — List, create, edit, and deactivate billing categories via `CnIndexPage` + `CnDetailPage`. No custom CRUD code is needed — OpenRegister and `@conduction/nextcloud-vue` handle all data operations.

4. **Category-based time entry filtering** — Filter the time entry list by billing category using `CnFacetSidebar` faceted navigation, enabling managers to view billable-only or WBSO-only hours with one click.

5. **Dashboard reporting widget** — Hours-per-category donut chart using `CnChartWidget` (ApexCharts) for utilization analysis, integrated into the pipelinq dashboard via `CnDashboardPage`.

## Scope

- New `billingCategory` schema added to `lib/Settings/pipelinq_register.json`
- Category management list view (`src/views/billingCategories/BillingCategoryList.vue`)
- Category facet filter in time entry list view (extends `time-entry-core` list)
- Dashboard widget: hours per billing category (`src/components/dashboard/BillingCategoryWidget.vue`)
- i18n keys for category types, UI labels, and validation messages (Dutch + English)
- Seed data: 5 standard Dutch billing categories (Declarabel, Niet-declarabel, Intern, WBSO O&O, DBA Opdracht)

**Depends on:** `time-entry-core` (provides `timeEntry` schema, list view, and create/edit dialog)

## Out of scope

- Billing rates per category (separate invoicing change)
- Automatic invoice generation from billable hours
- WBSO project management and RVO submission file generation
- DBA contract lifecycle management
- Budget allocation and variance tracking per category
- Approval workflows for time entry submission
- Category hierarchies or parent/child nesting

## Success Criteria

- An administrator can create, edit, and deactivate billing categories with code, name, type, color, and Dutch compliance flags
- When creating or editing a time entry, the user can select from active billing categories; the default category is pre-selected automatically
- WBSO-flagged categories display a notice when `requiresWbsoRef` is true and no WBSO reference is provided on the time entry
- The time entry list can be filtered by billing category using the facet sidebar; selected category persists across page navigation
- A dashboard widget shows total hours per billing category as a donut chart with Dutch-language labels
- `npm run build` produces zero errors after all changes
