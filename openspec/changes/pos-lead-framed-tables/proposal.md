# Proposal: pos-lead-framed-tables

## Why

pl4 on boards PqKassabon (8), PqKassadienst (7) and PqLead (9): the board draws the card's list as a framed table. It
has a 1px frame inside the card, a white head with a rule, and 18px outer insets. Live drew a grey head band without a
frame at 12px. The receipt lines and the drops also read as blue links on live, where the board draws plain text.

## What changes

- The receipt lines, the shift drops and the lead products set `tableFrame: true` (nc-vue
  feat/pq-c11-object-list-table-frame). Before nc-vue has the key, the tables stay as they are.
- The receipt lines and the drops set `rowTitle: "text"`: the first column reads in the main ink at regular weight.
