# Proposal: contact-moments-list-board

## Why

pl2 measured the contact moments list (the tickets list opened with `?ticketType=interaction`) against board
PqContactmomenten: the primary button read "Nieuw verzoek" where the board has "Contact vastleggen", and the table cut
every text off where the board wraps it, shows the reference under the subject, the channel with its direction
("Telefoon · inkomend") and the time as a day and a time ("Vandaag, 09:12").

## What changes

- The Tickets list declares a `log-contact` header action (a ticket form with `ticketType: interaction`); the contact
  moments preset makes it its primary header button. The requests list's Actions menu names only Import, so it is
  unchanged.
- The preset's columns: the subject with `{reference}` beneath it, the channel as `{channel} · {direction}` in words,
  when as `{occurredAt|relativetime}`, and subject, client and outcome wrap.
