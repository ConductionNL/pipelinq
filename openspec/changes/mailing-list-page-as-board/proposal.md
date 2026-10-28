# Proposal: mailing-list-page-as-board

## Why

Board PqMailinglijst draws the mailing list page with every list detail in the Lijstgegevens card, Geschiedenis on top of
the side column with three changes, the sign-up form and the subscriber preview as titled cards, and the Abonnees card
with its subtitle and buttons in its header and each subscriber as a name above the address (pl2: 18 items). Live showed
one detail behind "Alle 7 velden tonen", untitled side content, a History card at the bottom full of reads, and bare
addresses.

## What changes

- The Lijstgegevens card shows all seven details and edits them through Bewerken only (`collapsedFields`, `editable`).
- The side column keeps its declared order (`sideColumnOrder: declared`, nextcloud-vue detail-side-column-order), so
  History is on top; the timeline's `limit: 3` now holds and reads are left out (same nc-vue change).
- The sign-up form and the subscriber preview widgets are titled cards.
- The Subscribers section draws its own card: title, subtitle and Refresh in the header, board table spacing, dates in
  the reader's language.
- `GET /api/mailing-lists/{id}/subscriptions` names each row's contact (contact person, then client), so the row reads as
  the name above the address. A public signup with no contact keeps its address alone.

Not in this change: "Aanmeldingen beheren" (the board's second header button) needs a list-side management flow that
pipelinq does not have; the column sort and filter buttons; the header's "3 bevestigde abonnees · aangemaakt door".
