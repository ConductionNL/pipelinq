# Proposal: sales-overview-board

## Why

pl4 on board PqVerkoopoverzicht (15 strict failures). The KPI tiles show a number with nothing under it, where the
board says what each is made of ("1 gewonnen, 1 verloren", "van € 509.928 open, geen lead heeft een score"). Recent
gewonnen / verloren prints the amount as plain text where the board draws a green or red pill. A deal closing soon
loses its whole meta line when it has no owner. "Gewonnen tegenover open" is drawn as a single count with a trend,
"Dekking pijplijn" as a percentage of the target, and the "Passende prospects" card is missing. The stage, source
and customer charts are ApexCharts bar charts with axes, where the board draws labelled bars with a short figure.

## What changes

- The commercial overview (`GET /api/analytics/commercial`) also returns `wonCount`, `lostCount` (window and
  previous window), `openCount`, `scoredOpenCount`, `wonShareOfOpen`, `targetRemaining` and `pipelineCover`.
- Each KPI tile gets a caption from those figures. The trend chip stays: the page compares with the previous period.
- Closing soon and Recent won / lost write their meta line as parts, so an empty owner drops only itself.
- Recent won / lost shows the amount as a pill, green for won and red for lost (nextcloud-vue
  record-list-pill-case-template).
- Won against open is a two-figure card (won, open) with the share under it.
- Pipeline cover is the open pipeline over what is still to come in against the target, as a multiple ("2.1 x"),
  with "Set a pipeline target" when no target is set.
- Matching prospects lists the two best scored prospects from the KVK with their fit, and links to the prospects page.
- The stage, source and customer charts are bar lists with a short figure (nextcloud-vue chart-compact-number).

## Not in this change

- The count before each stage figure ("4 · 38k"): a bar list draws one value per row.
- The bar above the pipeline cover figure: a stat tile has no bar.
