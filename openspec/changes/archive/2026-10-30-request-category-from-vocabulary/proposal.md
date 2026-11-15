---
kind: code
---

# Pick a request's category from the tenant's own list

## Why

Board PqNieuwVerzoek draws Categorie as a select ("Vergunningen" with a chevron). Live drew a free-text field, so two handlers file the same question under "vergunning", "Vergunningen" and "permits", and the "Requests by category" chart splits one subject over three bars.

The list already exists as configuration: `ticketCategory` objects (register.d/99-zz-ticket-category-vocabulary.json) name each code a ticket stores, and the Zuiddrecht example set seeds seventeen of them (example-sets/zuiddrecht.d/76-ticket-categories.json). The dashboard chart already reads names from it. Only the create and edit form ignored it.

## What changes

- The request form reads the tenant's active `ticketCategory` objects and offers them as a select: the name is shown, the key is stored in `ticket.category`, as before.
- A tenant without any category keeps the free-text field, so nobody loses the ability to file a category.
- A ticket whose stored category is not in the list keeps it: the select shows the stored value as its own option, so editing never drops it.
- No schema change and no new data: the list stays configuration, seeded per tenant.

## Out of scope

Managing the list. Admins keep editing `ticketCategory` objects where they do today.
