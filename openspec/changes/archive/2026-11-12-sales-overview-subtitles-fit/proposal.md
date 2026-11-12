# Proposal: sales-overview-subtitles-fit

## Why

pl8 on board PqVerkoopoverzicht (items 1-9): the revenue charts lack the subtitle the board draws under their title
(the gap under the title is 20px on the board, 13.8 live), and the cards on the 12-column grid keep the fixed height of
their rows, so every card below the first row sits 24 to 241px lower than the board's two stacked columns.

## What changes

- "Revenue per month", "Revenue by source" and "Largest customers by revenue" carry the subtitle "Won deals"
  ("Gewonnen deals").
- Every card but the revenue chart ends at its content (`sizeToContent`), and the page packs its grid
  (`gridFloat: false`), so the cards under a shorter card move up, as the board stacks them.

## Not in this change

- The board's "EUR, " in front of the subtitle: a widget subtitle cannot name the reporting currency, and a fixed
  "EUR" would be wrong where the currency differs. "Last 90 days" under the largest customers is left out: the widget
  sums every won deal, not a period.
- The board draws revenue per month as columns with the value above and the month below; the library chart is an
  apexcharts bar chart. A column kind of the chart widget is library work.
- "Pipeline cover" as a titled card (board "Dekking pijplijn"): the stat card draws no title row.
