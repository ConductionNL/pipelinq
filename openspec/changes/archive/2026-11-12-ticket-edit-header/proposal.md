# Proposal: ticket-edit-header

## Why

pl6 on board PqTicketBewerken (30 Oct): the ticket edit dialog shows "Ticket bewerken" alone. The board names the
ticket in the header: "Bewerken · PQ-2026-0417" over the title and "PQ-2026-0417 · Verzoek" under it.

## What changes

- The simple profile's ticket page sets `editEyebrow: "Edit · {reference}"` and `editSubtitle: "{reference} ·
  {ticketType}"` (nextcloud-vue detail-edit-form-header-from-record). Before that library change the subtitle would
  show its tokens as written, so this ships with it.
