# Proposal: tonal-card-actions-pos-and-lead

## Why

pl4 on boards PqKassadienst (13-15) and PqLead (15): the board draws the card actions as light-blue tonal pills
(Goedkeuren, Afwijzen, Afstorting toevoegen, Product toevoegen); live drew white outlined buttons. nc-vue now carries
the tonal look for facts card `actions[]` (`variant: "tonal"`, 2.160.0) and for an object list's add button
(`addVariant: "tonal"`, feat/board-tonal-card-actions).

## What changes

- CashShiftDetail: the cash difference's Approve and Reject carry `variant: "tonal"`.
- CashShiftDropsWidget: Add drop carries the shared `cn-button--tonal` class.
- LeadDetail: the products list sets `addVariant: "tonal"`. Before nc-vue has the key, the button stays as it is.
