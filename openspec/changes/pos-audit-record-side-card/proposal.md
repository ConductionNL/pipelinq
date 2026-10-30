# Proposal: pos-audit-record-side-card

## Why

pl5 on board PqKassaAudit: the board draws an opened audit entry as a 360px card beside the list. The page declared no
pane width, so the pane took the default 42%, and the record's three sections kept fixed grid rows (2 and 6 rows), so
in a narrow pane the verification text was cut off and the summary left a blank band under it.

## What changes

- The audit log's split view declares `paneWidth: "360px"`. With nextcloud-vue's board side column
  (board-split-pane-side-column) that is the side card beside the 740px list column.
- The three sections of the audit entry (verification status, summary, cryptographic data) size to their content.

## Depends on

nextcloud-vue `fix/pq-c24-split-pane-side-column` for the side-column placement and the card. Without it the pane is
360px beside the table, which is still readable.
