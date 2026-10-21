# Relationship types on the server

## Why

The relationships card was an app-local component holding the relationship type table, the inverse and category of each type, the target kind and the duplicate check in the browser. A write from anywhere else (an import, the API) skipped all of it. nextcloud-vue's `object-list` now groups, opens a row by a field, resolves a label from a schema a field names and posts a create to an app endpoint, so the card is configuration and the rules move to the server.

## What changes

- `GET /api/relationship-types` serves the type table, labels translated, with the category to group by.
- `POST /api/relationships` creates a relationship: the type must be known, the parties different, and the same relationship must not exist yet (409).
- `RelationshipTypeListener` sets `inverseType`, `category` and `toType` before any relationship is stored.
- `RelationshipInverseListener` also removes the inverse at the old party when a relationship is moved to another party.
- ClientDetail and ContactDetail list relationships with an `object-list` (grouped by category, the end date red once past, the other party opens on its own page, add through the endpoint, edit and delete in place).
- The relationship schema (1.2.0) shows notes, dates and strength on the form.
- Removed: `ContactRelationships`, `RemoveRelationshipDialog` and the `ContactRelationships` and `ContactRelationshipsWidget` registry entries.

## Needs

nextcloud-vue `object-list` with `rowRouteField`, `createEndpoint` and `fkResolve` `schemaFrom` (branch `feature/pl-abstract-pl1-object-list-by-field`). Stacked on `relationship-and-channel-rules-on-the-server`.
