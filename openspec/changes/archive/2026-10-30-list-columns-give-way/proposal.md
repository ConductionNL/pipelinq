# Board-look lists show the columns that fit

## Why
On :8080 the website submissions, contracts and export jobs lists showed more columns than their content column holds, so the header labels were clipped. The boards (PqAanvragen, PqContracten, PqBiExport) draw at most six data columns, as the dossiq lists do.

## What changes
- Columns that do not fit start hidden (`hidden: true`). They stay in the Columns menu, so a user can still show them.
- Website submissions: the page (`sourceUrl`) and the handler (`assignee`) start hidden.
- Contracts: the billing period (`billingInterval`) starts hidden, and the number column is labelled Number.
- Export jobs: the next run (`nextRunAt`) starts hidden, because the schedule column already says when.
