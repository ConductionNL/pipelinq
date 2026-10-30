# Proposal: werkplek-in-call-columns

## Why

pl4 on board PqWerkplekKlant: in a call the page closes the client cards up behind the hidden dashboard cards, so the
requests, the recent contact and the tasks fill the left two columns and "Wie is het" ends up at the bottom of the right
column. The board draws three columns: who is it on the left, this contact moment and the open requests in the middle,
the knowledge base, tasks and callback requests and earlier contact on the right.

## What changes

- The simple profile's contact centre page declares `layoutWhen` (nextcloud-vue dashboard-layout-when): while
  `@workspace.callActive` holds, the page draws the in-call cards in the board's three columns.
- Without a call the page keeps its own layout. Before the library change the key is ignored and the page is as today.

## Not in this change

- The row of six quick-action tiles (Nieuw verzoek, Terugbellen, Afspraak boeken, Naar backoffice, Klacht registreren,
  Ander kanaal): the library has no tile row whose tiles open a form or dialog for the client in the call.
- The header's "In gesprek" menu button, the timer chip and the call pills on the meta line.
