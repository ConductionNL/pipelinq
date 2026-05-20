# Proposal: Admin Settings — Duplicate Prevention

## Problem
The TagManager component (used for lead sources and request channels) does not prevent duplicate entries. Users can add "website" twice without warning.

## Solution
Add client-side duplicate detection in the TagManager's saveNew and saveRename methods, comparing case-insensitively against existing tags. Display an error message when a duplicate is detected.

## Scope
- `src/views/settings/TagManager.vue` — add duplicate check
- `l10n/en.json` and `l10n/nl.json` — add translation key for duplicate message



## Tasks

# Tasks: Admin Settings — Duplicate Prevention

1. [x] Add duplicate name check in TagManager.saveNew()
2. [x] Add duplicate name check in TagManager.saveRename()
3. [x] Add translation keys for duplicate error message (en + nl)