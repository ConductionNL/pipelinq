---
status: draft
---

# POS Staff PIN + Role Permissions

## Purpose

PIN login per staffer; permission matrix (void, discount %, refund, no-sale).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** none

## Cross-app integration

Per-staff sales report feeds shillinq commission journal.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Role + user permissions :: Cashier/manager roles; PIN
- dvi-salonsoftware :: Medewerker-omzet en provisie-rapport :: Per-stylist commissie en omzet
- erpnext-pos :: POS Profile = cashier permission set :: Per-cashier profile controlling allowed items/payments/discounts
- korona-cloud :: Time clock + sales-per-staff :: Clock-in, commission tracking, schedules
- lightspeed-retail :: Staff timesheets + sales-per-staff commission :: Clock in/out, commission, performance
- mews-pos :: User PIN + role permission :: Pin login per staffer, void/discount permission
- odoo-pos :: Cashier login + restrictions per role :: Cashier PIN/badge, restrict actions per role
- salonized :: Staff overzicht + sales per medewerker :: Per-stylist revenue, commission report
- salonkee-pos :: Per-staff commission + revenue dashboard :: Commission rules per service category
- shopify-pos :: Staff PIN + role permissions (Pro) :: Granular role/permission per staffer; sales attribution
- square-pos :: Staff PIN login + permissions :: Time clock, sales-per-staff, role permissions
- toast-pos :: Staff scheduling + payroll (Toast Payroll) :: Built-in scheduling, time clock, tip pooling
- unicenta-opos :: User + permission roles :: Cashier/admin/manager; permission per action

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 13 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
