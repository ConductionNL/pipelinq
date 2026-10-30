# Proposal: caller-chooser-board-table

## Why

pl5 on board PqInkomendGesprek 1-3: the caller chooser ("Inkomend gesprek: kies contact") drew its matches 695px wide
in a 592px content area, so the Select buttons ran past the dialog edge and were cut off. A board-look table rule keeps
cells on one line, so a long address pushed the table wider. The head drew in main ink on a grey ground, the cells
started 12px in from the content edge, and the names were regular weight.

## What changes

- The matches table has a fixed layout over the content width, the columns share it as the board draws them, and long
  addresses wrap.
- The name column starts and the button column ends flush with the content edge.
- The head is 13px semibold in soft ink on the dialog ground; cells are 14px; the name is semibold.

## Not changed

- The dialog's 1px edge on the board: every other board dialog draws none (board call).
- The close button's focus ring: the dialog focuses it on open, and the ring stays for keyboard users.
