# Proposal: ticket-edit-form-footnote

## Why

pl8 on board PqTicketBewerken (1 Nov): the board closes the ticket edit form on a note saying the status is not
edited there, it moves through the ticket's steps. Live has no note: the form had no place for one.

## What changes

- The simple profile's ticket page gives its edit form the note (`editFootnote`, nextcloud-vue 24u3
  feat/pqt9-edit-form-footnote), naming the steps the page has: Answer the customer, Complete, Reject.

## Not in this change

- The board's second sentence (a complaint shows Klachtcategorie and Oplossing, a contact moment Richting and Duur):
  the simple edit form shows the same fields for every ticket type, so the sentence would not be true.
