# Proposal: screens-pos-lists-board

## Why

The drawn boards are the UI canon. The point of sale lists did not match theirs: the transactions list (PqKassabonnen) had no chips, no created column and no footer; the Z-reports list (PqZRapporten) was titled "Boekhoudkundige Afhandeling" with raw status codes; the cash shift list (PqKassadiensten) fetched its rows by hand, so it could not carry the board's chips.

## What changes

- Transactions: count line, the chips All, Today, Parked and Refunded with counts, the status as a pill, the created time, Download, and a footer note.
- Z-reports: titled Z-reports, count line, the chips All, Not booked and Failed, status labels, Download and a footer note.
- Cash shifts: the list reads itself (CnIndexPage self-fetch) with the chips All, Open and To review, the board columns including a count of the shift's deposits, and a footer note. Opening a shift still goes through the server's open endpoint.
- Labels for the Z-report and cash shift status values (`lib/Settings/register.d/99-zz-pos-board.json`), shown after the register is imported again.

Not yet: the board's Difference column on the shift list needs a sum over the shift's cash difference records, which the library's aggregate column cannot do yet (count only).
