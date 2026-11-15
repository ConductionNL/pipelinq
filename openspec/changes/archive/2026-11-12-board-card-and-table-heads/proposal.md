# Proposal: board-card-and-table-heads

## Why

COMPDIFF pl6 measured the app's own cards and tables against the boards:

- PqZoekinzicht's four card titles 17px/700 on a 25.5px line (the body's 1.5) where the boards draw 20.4px, the font's
  normal line; PqVerzendmonitor "Recente gebeurtenissen" also at 600 instead of 700.
- The table heads of PqZoekinzicht (search queries, keywords, competitors) and PqZRapport (Boekhouding) on #edeff2 under
  a rule, some 15px or bold: the theme's bare `th` rule. The Dq boards draw every card table head 14px/600 in the muted
  ink on #fafbfc with no rule under it (DqZaaktype, DqTermijnen, DqZaakGerelateerd), as the library tables do.
- PqVerkoopoverzicht's "Sluit binnenkort", "Recent gewonnen / verloren" and "Passende prospects" draw a rule above the
  first row, as the Dq dashboard lists do (DqMijnWerk, DqMijnTeam); the record list does that with `ruleAbove`, which
  the manifest did not set.

## What changes

- Card titles in SearchQueries and BlastMonitor's timeline: 17px, 700, `line-height: normal`.
- Table heads in SearchQueries, CompetitorWatches, KeywordIntelligence and ZReportBookkeepingWidget: 14px/600, muted
  ink, the board head ground (`--cn-board-table-head-bg`), no rule under the head.
- Manifest: `ruleAbove: true` on the three dashboard record lists.

No schema change, no new strings.
