# Proposal: shared-label-list

## Why

Board PqBeheerMarketing draws a Labels card: "De labels die mensen kunnen kiezen bij artikelen, leads en klanten", five labels as chips (afval, duurzaam, parkeren, ondernemers, evenementen), a "Nieuw label" field with Toevoegen, and the line "Dubbelklik op een label om het te hernoemen. Een verwijderd label blijft staan op items die het al gebruiken."

Today only `article` carries `tags`, as free text. `lead` and `client` carry no labels at all, and nothing offers a shared list, so two colleagues write "Ondernemers" and "ondernemer" and a segment or a filter misses half of them.

## What changes

- A `label` schema in the pipelinq register: one object per label, `name` (unique, case-insensitive) and an optional `description`. It is the vocabulary, not a link: an item stores the label's name.
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
1. Schema: `label`, `lead.labels`, `client.labels` (register.d fragment).
2. Admin card as manifest config over a generic nc-vue chip-list manager over an OpenRegister schema (add, rename on double-click, delete), so other apps can reuse it.
3. Pickers: the forms' `labels` / `tags` field gets `fieldOverrides` with the label list as its options source.
4. Rename pass: `LabelRenameService` (writes as the caller, after the update right on each item is checked by OpenRegister).

## Open questions (coordinator)

- Rename rewrites items (proposed) or only the list (like delete)? The board says nothing about rename and items.
- Should the shipped set seed the five board labels, or the Zuiddrecht example set only? Proposed: the example set only.
