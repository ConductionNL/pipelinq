---
status: draft
---

# Time Entry Mobile (offline timer + sync)

## Placement & Information Architecture

**Placement type:** `INFRA` — Cross-cutting infrastructure with no end-user surface (or only an internal/admin one). No menu item; backend wiring only.

**Lives at:** — *(consumed by mobile app)*

**Rationale:** Offline-sync; same UI on mobile.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

iOS/Android offline timer + sync.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 18/26 competitors
- **Dependencies:** time-entry-core

## Competitor Evidence (from intelligence-db)

- anuko-time-tracker :: Mobile-responsive (no native app) :: Responsive web only
- bigtime :: Mobile app with offline :: iOS/Android timesheet entry offline
- clio :: Mobile timer with offline :: iOS/Android offline timer
- clockify :: GPS tracking field workforce :: Optional location pin for jobsite tracking
- clockify :: Mobile iOS Android timer with offline :: Full mobile parity with desktop
- harvest :: Mobile timer iOS Android :: Native apps with offline mode and sync
- hubstaff :: GPS and geofencing mobile :: GPS tracking; auto clock-in when entering job site
- hubstaff :: Mobile iOS Android apps :: Full feature parity mobile
- kantata :: Mobile time entry :: Mobile timesheet for consultants on the go
- kimai :: Mobile-friendly responsive UI plus 3rd-party apps :: No native app from Kimai; community apps exist
- replicon :: Mobile native + offline :: iOS/Android with full feature parity
- tempo-timesheets :: Mobile iOS Android :: Mobile time entry
- timecamp :: Mobile app with GPS :: iOS/Android; optional GPS
- timely :: Mobile iOS Android :: Mobile Memory + entry app
- toggl-track :: Mobile app with offline timer :: iOS/Android continue tracking offline; auto-sync

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 15 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
