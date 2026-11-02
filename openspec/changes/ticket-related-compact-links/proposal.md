# Proposal: ticket-related-compact-links

## Why

pl8 on boards PqTicket, PqTicketBesluitvorming, PqTicketContactmomenten and PqTicketGerelateerd (1 Nov): the ticket's
"Gerelateerd" side card lists all seven related records as rows with wrapping icons (the contact, two tasks, the
booking, three tickets). The boards draw one compact link: the appointment that belongs to the ticket ("Afspraak:
locatiebezoek Parkstraat, 15 oktober").

## What changes

- The ticket's related side card uses the library's `layout: "links"`, with one source: the appointments whose
  `ticket` names this ticket, labelled "Appointment: {subject}, {startAt|longdate}" and opening the booking.
- Every relation stays reachable: the Gerelateerd tab, and "Show all" on the card.

## Not in this change

- The task page's related card: board PqTaak draws rows with icon tiles, the count pill and "Toevoegen" stay.
- "Show all" under the link: the library draws it whenever the record has relations of its own; the board does not.
