# Proposal: contact-person-page-as-board

## Why

Board PqContactpersoon draws the contact page with one tab strip holding every list and panel the person has (Overzicht
through Geschiedenis, 14 tabs), Overzicht as one card of headed sections (Contactgegevens, Deals, Relaties, Kanalen), and a
short side column (Openstaande deals, Gerelateerd, Geschiedenis with three changes). Live had seven tabs, the deals as a
second card, and channels, relationships, subscriptions, BRP, mail, messages and meetings as a long side column (pl2: 17
items). The board is the UI canon (decision 130), so it replaces the seven-tab layout of the 7 October audit.

## What changes

- The strip: Overview, Deals, Requests, Contact moments, Bookings, Relationships, Subscriptions, BSN and BRP, Channels,
  Mailbox ("Mail"), Messages, Appointments, Files, History.
- Overview is a `sections` widget: the details (all eleven fields, the preferred name kept), the deals, the
  relationships and the channels. A section widget under its own heading draws no card of its own (`content.card: false`).
- The side column: open deals, related, and a History card with the three newest changes (nextcloud-vue
  detail-side-column-order gives the timeline its `limit` and leaves reads out).

Not in this change: the "Nextcloud Contacten" sync card (no such status exists yet: a feature), the compact look of
Relaties and Kanalen in Overview, the Gerelateerd card's link list, and the Waarschuwingen card, which the board does not
draw but which holds the add-a-warning action (board call).
