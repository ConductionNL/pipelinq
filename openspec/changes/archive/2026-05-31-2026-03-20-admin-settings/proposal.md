# Proposal: Admin Settings — Duplicate Prevention

## Problem

The `TagManager` component — used in both lead source configuration and request channel configuration on the admin settings page — does not prevent duplicate entries. A user can add the value `"website"` twice without any warning, and can rename an existing tag to a name that already exists. This produces silently corrupt configuration data: duplicate options appear in dropdowns, confuse agents, and produce invalid filter results.

## Solution

Add client-side duplicate detection inside `TagManager.vue`:

- In `saveNew()`: before emitting the `add` event, compare the trimmed new name case-insensitively against all existing tag names. If a match is found, set the component's `error` state and return early without emitting.
- In `saveRename()`: before emitting the `rename` event, compare the trimmed new name case-insensitively against all existing tag names **excluding the tag currently being renamed** (a tag may be renamed to the same name with different casing without error). If a match is found, set the `error` state and return early.
- In both cases, display the error via the existing `NcNoteCard` error slot already present in the template.
- Add the translation key `"An item with the name \"{name}\" already exists."` to both `l10n/en.json` and `l10n/nl.json`.

No backend changes are required. The TagManager receives its tag list as a prop; the duplicate check is performed in memory against this prop.

## Scope

- `src/views/settings/TagManager.vue` — add duplicate check in `saveNew()` and `saveRename()`
- `l10n/en.json` — add translation key for duplicate error message
- `l10n/nl.json` — add Dutch translation for duplicate error message

## Out of scope

- Server-side uniqueness enforcement (duplicate prevention at the API layer)
- Deduplication of values already stored in existing configurations
- Case normalization or trimming of stored tag values

## Success Criteria

- Adding a tag name that already exists (any case) shows an error and does not emit `add`
- Renaming a tag to a name already used by a different tag shows an error and does not emit `rename`
- Renaming a tag to its own name (e.g. same casing) is allowed
- Error message is translated correctly in both English and Dutch
- No regressions in normal add/rename/remove flows
