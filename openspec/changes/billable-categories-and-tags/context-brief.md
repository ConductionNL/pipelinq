---
status: draft
---

# Billable / Non-billable / Internal / WBSO / DBA Tags

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Projecten & Tijd → Categorieën

**Rationale:** Tag-beheer.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Billable / non-billable / internal / training / sick / WBSO / DBA-project tags; flexible reporting.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 20/26 competitors + NL moat
- **Dependencies:** time-entry-core

## Competitor Evidence (from intelligence-db)

- anuko-time-tracker :: Time-off / PTO :: Time-off types and balances
- clockify :: Time off and PTO tracking :: Vacation/sick days inline with timesheet
- everhour :: Tags for billable categories :: Tag entries; report by tag
- everhour :: Time-off / PTO tracking :: PTO request and approval; balances per user
- harvest :: Billable vs non-billable flag :: Task-level billable toggle; reporting by category
- hubstaff :: Time-off PTO requests :: PTO type config; balance tracking
- kimai :: Tags and metadata :: Tag entries for R&D, WBSO, training etc.
- tempo-timesheets :: Billable / non-billable flag :: Per-account billable flag; CapEx/OpEx categorization
- timecamp :: PTO and time-off tracking :: Vacation/sick types; balance reporting
- timely :: Tags for billable categories :: Tag entries; report by tag
- toggl-track :: Tags for billable categories :: Flexible tag system for billable, R&D, training

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 11 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
