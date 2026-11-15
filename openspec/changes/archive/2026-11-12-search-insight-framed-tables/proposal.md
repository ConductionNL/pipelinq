# Proposal: search-insight-framed-tables

## Why

pl8 on board PqZoekinzicht (items 1-12): the board draws its four tables alike, framed in the card (1px edge, 8px
radius, 18px outer insets), the head on the board head ground (#fafbfc) and a sort and a filter button in every data
column head (head row 42px). Live, the search queries table was a CnDataTable without the frame or head controls (12px
insets, 38.3px head), and the keyword, competitor and connection audit tables were the app's own markup on a white
head (36.8px head, own frame).

## What changes

- The search queries, keyword ("one push from page one", "nothing of ours answers this"), competitor watch and
  connection audit tables are CnDataTables with `framed`, `filterable` and `localHeaders`: they sort and filter the
  rows they hold from their heads, without a new request.
- Every data column sorts; number columns filter as numbers; the confirm and run columns carry no head controls.
- The competitor table sorts and filters on the words it shows (competitor name, kind, schedule, outcome).
- The page no longer paints the table heads white: the board head ground applies.

## Not in this change

- The per-page table of a two-pages finding, shown on the full keywords page only, stays as it is.
- The download keeps the server order until a head sorts; a head filter does not narrow the download.
