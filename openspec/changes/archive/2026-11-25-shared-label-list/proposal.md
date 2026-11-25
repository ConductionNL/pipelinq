# Proposal: shared-label-list

## Why

Board PqBeheerMarketing draws a Labels card: "De labels die mensen kunnen kiezen bij artikelen, leads en klanten", five labels as chips (afval, duurzaam, parkeren, ondernemers, evenementen), a "Nieuw label" field with Toevoegen, and the line "Dubbelklik op een label om het te hernoemen. Een verwijderd label blijft staan op items die het al gebruiken."

Today only `article` carries `tags`, as free text. `lead` and `client` carry no labels at all, and nothing offers a shared list, so two colleagues write "Ondernemers" and "ondernemer" and a segment or a filter misses half of them.

## What changes

- An `itemLabel` schema in the pipelinq register (not `label`: OpenRegister slugs are global and planninq owns `label`): one object per label, `name` (unique, case-insensitive) and an optional `description`. It is the vocabulary, not a link: an item stores the label's name.
- `lead` and `client` get `labels` (array of strings), next to `article.tags`, which keeps its name and is read as the same kind of value.
- The pickers on the article, lead and client forms offer the label list (an `NcSelect` with the list as options; typing a new value is refused, so the list stays the source).
- Beheer, Marketing: a Labels card lists the labels as chips, adds one, renames one on double-click, and deletes one.
- Renaming a label rewrites the name on the items that carry it, in one server-side pass (a background job past a threshold), so a filter on the new name finds them. Deleting a label removes it from the list only: items keep the text, as the board says.
- Segments and list filters can use `labels` like any array field (`containsAny`).

## Not changed

- Nextcloud's own system tags (the `tags` integration) stay what they are: per-file and per-object collaborative tags with their own lifecycle. The board's labels are a marketing vocabulary that survives a delete, which system tags do not.
- `article.tags` keeps its property name; no data migration of existing free-text tags. Existing values that are not on the list still show, and the list can adopt them ("Toevoegen" with the same name).

## Size and order

Not small: two schema changes (lead, client: additive, no migration), one new schema, three form pickers, one admin card, and a rename pass. Suggested order:
1. Schema: `itemLabel`, `lead.labels`, `client.labels` (register.d fragment).
2. Admin card, last on the Marketing tab as the board draws it, over a generic nc-vue chip-list manager over an OpenRegister schema (add, rename on double-click, delete), so other apps can reuse it.
3. Pickers: the forms' `labels` / `tags` field gets `fieldOverrides` with the label list as its options source.
4. Rename pass: `LabelRenameService` (writes as the caller, after the update right on each item is checked by OpenRegister).

## Decisions (Ruben, 29 Oct 03:00)

- Renaming a label rewrites it on every lead and client that carries it. Articles carry the same list in `tags`, so the rename rewrites those too.
- The five board labels ship in the Zuiddrecht example set only. A fresh install starts with an empty list.
