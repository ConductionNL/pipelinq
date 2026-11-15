# Proposal: client-side-cards-title-add

## Why

COMPDIFF pl4 measured the PqKlant and PqOrganisatie side cards Contactkanalen and Relaties without a button in their
title row (`header.actions 1>0`): the page drew "Kanaal toevoegen" and "Relatie toevoegen" under the list. Notities
opened on its composer, so it had no "Notitie toevoegen" in the title row and no rule above the first note. The boards
draw all three in the title row, as the outlined card button of the Dq boards (DqTaak "Notitie toevoegen").

## What changes

- New registry types `ContactChannelsCard` and `ContactRelationshipsCard`: the same sections in the library card
  (`chrome: "card"`), which report their add for the card's title row when `content.headerAdd` is set. The channels
  card reports one "Add" menu (e-mail address, phone number, social profile), the relationships card one "Add" button
  that opens the add dialog. `sectionWidget` passes a section's `header` event on to the host.
- ClientDetail's `client-channels` and `client-relationships` use them; `client-notes` keeps its composer behind the
  header button (`props.composer: "button"`).
- The contact page keeps `ContactChannelsWidget` and `ContactRelationshipsWidget` as they are.

## Depends on

nextcloud-vue `feat/pq-c19-header-card-button` (a `variant: "secondary"` header action and its `items` menu). On an
older library the add actions are dropped from the title row (no `run`) or drawn as the tinted link.
