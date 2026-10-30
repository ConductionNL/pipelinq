# Proposal: ticket-contacts-title-row

## Summary

Board PqTicketContactmomenten draws the Contact moments tab with a title row: "Contactmomenten", "2 bij dit ticket, nieuwste eerst" under it and an outlined "Contact vastleggen" with a phone icon beside it. Live draws no title row and puts Log contact as a wide tertiary button under the table. The tab sets `showTitle`, and the list puts its Add in the title row with the phone icon and says its count under the title.

## Scope

- `src/menu-layout.simple.json`: the Contact moments tab and its list.
- One string (en, nl).
- Needs nextcloud-vue feat/pq-c30-list-add-in-panel-heading (`addPlacement: "header"`, the panel heading drawing what the list reports). With an older library the keys are ignored and the page stays as it is.
