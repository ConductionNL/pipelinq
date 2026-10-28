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

- A Nextcloud Contacten side card: synced or not, the address book, the last change, and "Bijwerken in Contacten"
  (write-back), from a new `GET /api/contacts-sync/status`.
- Relationships and Channels in Overview in a compact look (`content.compact`), the tabs keep the full lists.

Not in this change: the Gerelateerd card's link list, and the Waarschuwingen card stays (users keep features; the board is amended to draw it).
