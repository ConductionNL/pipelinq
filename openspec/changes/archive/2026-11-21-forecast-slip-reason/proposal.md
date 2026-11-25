---
kind: code
depends_on: []
screens: [PqLead, PqForecast]
---

# A deal that slips out of its forecast period asks why

## Why

The forecast-category row promises two things: a seller marks a lead as commit or best case, and says why when it slips. The first half is built. A commit above the threshold (default EUR 50K) demands a justification (`lib/Service/ForecastDealService.php` `validateTransition()`, REQ-FRC-003). The second half is not: when a deal's expected close date moves past the end of its forecast period, nothing asks for a reason, and the manager only sees the deal drop out of the commit total. Ruben decided on 13 November 2026 to keep the commit reason as built and specify the slip reason (decision 411).

## What Changes

- A new requirement REQ-FRC-012 in `forecast-roll-up-and-categories`: when an open deal in commit or best case gets an `expectedCloseDate` after the end of the fiscal period it was forecast in, saving asks for a slip reason. The reason is stored on the deal with the old and new close dates.
- The forecast trend's moved-out list shows the slip reason next to each deal that slipped.
- No threshold: every slip of a commit or best-case deal asks, since the slip itself is the signal a manager reviews.
