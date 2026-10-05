# Design: simple-ticket-page

## D-1 An overlay, not an edit

`src/manifest.json` is not touched. The overlay for `TicketDetail` in
`src/menu-layout.simple.json` uses `config` (new keys, and `layout`),
`configPatch` (take `customer-reply` out of `bodyWidgets`), `configAppend` (two
widgets, two sections) and `configOrder` (conversation after the dossier).

## D-2 The stage is `status`

The lifecycle field. No materialised field, so nothing to backfill.

## D-3 Status actions are lifecycle transitions

`api-call` to `/apps/openregister/api/objects/@objectId/transition` with
`{ action }`, the call the library's own lifecycle component makes.
`visibleWhen` mirrors the transition's `from`, narrowed by ticket type where the
lifecycle's description names one. The spec reads the lifecycle from the schema
and fails when the two disagree.

`lifecycleActions` (the library's server-driven list) is not used: it would put
every allowed transition in the menu, the primary one a second time, with
labels taken from transition names.

## D-4 One place to answer

`TicketAnswerDialog` wraps `CustomerReplySection` and names the ticket from the
route. On close it emits `cn:page:refresh`, which makes the page read the
ticket again. `TicketConversationSection` reads the ticket from
`cnSectionContext`, so it follows that reload.

Had the section stayed in the body next to the dialog, two text areas would
hold the same answer and the one in the body would be stale after a save in the
dialog.

## D-5 One full-width tabs widget

Beside a side column the grid is narrow. The three cards the page has would
stack. They become three tabs, plus Contact moments. Four tabs, cap five.
