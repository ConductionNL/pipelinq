# Proposal: filter-menu-owner-and-street

## Why

Three boards draw a filter pipelinq does not offer yet. PqContracten and PqFlows filter on the owner and name the person ("Eigenaar: Pieter Jansen"); no column shows the owner, so the Filter menu built from the columns cannot offer it. On PqEvenement the registrations chip reads "Straat: Lindelaan", while the registration's address field is titled "Adres".

## What changes

- The Contracts list sets `filterMenu` and a `filterMenuFields` entry for `ownerId`, read as a Nextcloud user, so the menu and the Active chip show the display name. `salesContract.ownerId` becomes `facetable`, so the API returns the owner buckets the menu lists.
- The Flows page (named source `flows`) sets `filterMenu` and a `filterMenuFields` entry for `owner`, read as a Nextcloud user.
- The event's Registrations tab names the `address` filter "Street" through `filterLabels`.

## Needs

`@conduction/nextcloud-vue` 24t2s: `feat/filter-menu-fields` (the `filterMenuFields` entries and the flows source's owner filter) and `fix/column-filter-chip-label` (an operator key such as `address[like]` reads its field's `filterLabels` entry). On an earlier release the keys are ignored: the Contracts and Flows Filter menus list only their column values, and the chip keeps its current text.

## Not changed

- `ownerId` keeps `format: user`; no field is added or renamed. No new strings: "Owner" and "Street" are translated already.

## Rollback

Revert the commit. `facetable` only adds an aggregation; removing it drops the owner buckets.
