---
kind: code
---

# KCC report: top 10, occupancy, forecast, Excel and PDF

## Why
Board DqRapportages draws a KCC report per week: stat tiles, contact volume per channel, the top 10 subjects, occupancy per employee and hour, a capacity forecast, and an export to Excel and PDF. Pipelinq's `/api/rapportage` serves the KPIs, channels, agents, SLA and a CSV. The other parts have no source, and dossiq may not invent figures (Ruben, 6 Nov). Dossiq shows this report beside its own, and pipelinq counts it.

## What changes
- `GET /api/rapportage/top-subjects`: the ten subjects with the most contact moments in a period.
- `GET /api/rapportage/occupancy`: contact moments per employee and hour in a period.
- `GET /api/rapportage/forecast`: expected contacts per day for the next seven days, from the daily average, with closed days at zero.
- `GET /api/rapportage/export?format=xlsx|pdf`: the report as a workbook or a formatted document, beside the CSV.
- Every new route takes the filters team, channel and employee, and the same access check as the existing reporting routes.

## Out of scope
- Building any of this. This change only specifies it.
- The panel on dossiq's reports page, which follows once these routes exist.

## Open question
Which calendar marks a closed day: OpenRegister's working calendar, as dossiq's terms read it, is the proposal.
