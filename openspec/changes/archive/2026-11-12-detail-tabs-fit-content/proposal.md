# Proposal: detail-tabs-fit-content

## Why

ClientDetail and ProductDetail showed it on pl6 (bpq5/client-band-compact, bpq5/product-panels-fit-content): a tab
strip in a fixed-height grid cell is cut off when the open tab is taller than the cell, and leaves a stretched card or
a gap when it is shorter. The same layout is on eleven more detail pages. pl6 drew PqContactpersoon's and PqLead's
Overview as a card running far past its last field, and every other page can clip as soon as a record has more to show.

## What changes

- Every grid cell of TicketDetail, ContactDetail, LeadDetail, TaskDetail, CashShiftDetail, ZReportDetail,
  PosTransactionDetail, SocialPostDetail, BookingDetail, ProgrammeDetail and ContractDetail sizes to its content
  (`sizeToContent: true`), the figures on ProgrammeDetail and ContractDetail too.
- Those pages set `gridFloat: false`, so the grid takes 1px rows and a fitted cell ends at its content instead of on
  the next 80px row (as MailingListDetail).

## Reach

Manifest only. Works on the released nc-vue.
