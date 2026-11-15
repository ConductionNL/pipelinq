# The werkplek in a call names the caller

## Why
Board PqWerkplekKlant titles the in-call werkplek "Gesprek met Sanne de Vries" and shows a "Wie is het" card about the caller. Live kept "My customer contact" and had no caller card.

## What changes
- The header writes `@workspace.selectedClientName` (from the screen-pop match, or the client record), and the page title follows it through nc-vue `titleWhen` while `callActive` holds.
- A `facts` card, "Wie is het", shows the client in the call: name (linked), type and address, source, preference and language, and a link to the customer page.
- Not built: the board's "Gecontroleerd" row (an identity check the client record does not hold) and "Opzoeken in de BRP".
