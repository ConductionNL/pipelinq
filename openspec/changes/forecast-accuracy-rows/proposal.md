---
kind: code
screens: [PqForecast]
---

## Why

PqForecast draws a forecast accuracy table for the quarter that closed: per seller, the first week's commit, what the
quarter closed won, and the percentage. `ForecastTrend.vue` declared `accuracyRows` but never filled it, so the table
stayed empty even with the Q3 snapshots seeded.

## What changes

`forecastMath.js` gains `previousPeriodId` and `accuracyRows`. The latter sets each seller's first snapshot commit
against the last snapshot's closed-won amount. The forecast trend reads the previous quarter's rep snapshots, shows a
row per seller by display name, and leaves the table empty when the read fails.
