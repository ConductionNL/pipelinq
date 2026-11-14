# Proposal: queue-list-card-board

## Why

pl4 on board PqWachtrij: the list card opens with the kind lenses as a segmented control (Alle 12, Verzoeken 6,
Klachten 1, Contactmomenten 5) and "Soort, dan kanaal" beside them, the channel strip under them; the rows have no
column heads; the third tile reads "Binnen de termijn 9 van 12, pak de rode eerst". Live drew the lenses as pills above
the card, a table with sortable heads, and "Over de termijn 2".

## What changes

- `hideTableHeader: true`: plain rows, no column heads. The list keeps its order, oldest first.
- The tile "Past the deadline" gives way to "Within the deadline": the open unassigned tickets minus those past their
  deadline (a ticket without a deadline is within it), out of all open unassigned tickets (`limitSource`,
  `limitStyle: "of"`, nextcloud-vue stat-limit-of-a-count), caption "take the red ones first". The tiles read
  Unassigned, Longest waiting, Within the deadline.
- Before the library change the tile reads its count with a quieter "/ 12".

## Not in this change

- The lenses inside the list card: DqTeamwachtrij draws its lenses as chips above the card and Dq wins, so the
  PqWachtrij board is amended to match instead.
- The footer: the card already ends in the count, the note and the pager, as DqTeamwachtrij draws its queue footer.
