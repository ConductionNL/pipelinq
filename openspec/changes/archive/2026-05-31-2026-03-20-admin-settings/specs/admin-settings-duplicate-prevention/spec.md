---
status: implemented
---

# Spec: Admin Settings — Duplicate Prevention

## Purpose

Prevent administrators from creating duplicate tag values in the `TagManager` component used on the admin settings page (lead sources and request channels). Duplicate detection is case-insensitive and runs client-side before any emit. A clear error message is displayed when a duplicate is detected; no data is submitted.

---

## REQ-ASDP-001: Duplicate detection on add [MVP]

When adding a new tag via `TagManager.saveNew()`, the system MUST compare the trimmed input value case-insensitively against all existing tag names. If a match is found, an error MUST be shown and the `add` event MUST NOT be emitted.

### Scenario: Exact duplicate is rejected

- GIVEN a TagManager with existing tags `["website", "telefonisch", "e-mail"]`
- WHEN the user types `"website"` and confirms
- THEN `TagManager.saveNew()` MUST NOT emit the `add` event
- AND the `NcNoteCard` error MUST display `'An item with the name "website" already exists.'`
- AND the add input MUST remain open so the user can correct the entry

### Scenario: Case-insensitive duplicate is rejected

- GIVEN a TagManager with existing tags `["Website", "telefonisch"]`
- WHEN the user types `"WEBSITE"` and confirms
- THEN `TagManager.saveNew()` MUST NOT emit the `add` event
- AND the error message MUST be shown with the name interpolated as entered: `'An item with the name "WEBSITE" already exists.'`

### Scenario: Unique name is accepted

- GIVEN a TagManager with existing tags `["website", "telefonisch"]`
- WHEN the user types `"beurs"` and confirms
- THEN `TagManager.saveNew()` MUST emit the `add` event with the value `"beurs"`
- AND no error MUST be shown

### Scenario: Whitespace-trimmed name is validated

- GIVEN a TagManager with existing tags `["website"]`
- WHEN the user types `"  website  "` (with leading/trailing spaces) and confirms
- THEN the trimmed value `"website"` MUST be used for duplicate comparison
- AND the add MUST be rejected with the error message

---

## REQ-ASDP-002: Duplicate detection on rename [MVP]

When renaming an existing tag via `TagManager.saveRename()`, the system MUST compare the trimmed new name case-insensitively against all existing tag names **excluding the tag being renamed**. If a match is found on a different tag, an error MUST be shown and the `rename` event MUST NOT be emitted.

### Scenario: Rename to existing other tag is rejected

- GIVEN a TagManager with tags `[{ id: "1", name: "website" }, { id: "2", name: "telefonisch" }]`
- WHEN the user renames tag `2` to `"website"`
- THEN `TagManager.saveRename()` MUST NOT emit the `rename` event
- AND the error MUST display `'An item with the name "website" already exists.'`

### Scenario: Rename to same name with different casing is allowed

- GIVEN a TagManager with tags `[{ id: "1", name: "website" }, { id: "2", name: "Telefonisch" }]`
- WHEN the user renames tag `1` to `"Website"` (capitalised)
- THEN `TagManager.saveRename()` MUST emit the `rename` event with `(id: "1", name: "Website")`
- AND no error MUST be shown
  (Renaming to a casing variant of the tag's own current name is allowed)

### Scenario: Rename to unique name is accepted

- GIVEN a TagManager with tags `[{ id: "1", name: "website" }, { id: "2", name: "telefonisch" }]`
- WHEN the user renames tag `2` to `"beurs"`
- THEN `TagManager.saveRename()` MUST emit the `rename` event with `(id: "2", name: "beurs")`
- AND no error MUST be shown

### Scenario: Case-insensitive match on rename is rejected

- GIVEN a TagManager with tags `[{ id: "1", name: "Website" }, { id: "2", name: "telefonisch" }]`
- WHEN the user renames tag `2` to `"WEBSITE"`
- THEN `TagManager.saveRename()` MUST NOT emit the `rename` event
- AND the error MUST be shown

---

## REQ-ASDP-003: Error display [MVP]

The duplicate error MUST be displayed using the existing `NcNoteCard` component with `type="error"` already present in `TagManager.vue`. The error MUST be cleared when the user cancels the add or rename action.

### Scenario: Error shown in NcNoteCard

- GIVEN a duplicate is detected in `saveNew()` or `saveRename()`
- THEN the `NcNoteCard` with `type="error"` MUST become visible below the tag list
- AND the error text MUST contain the duplicate name interpolated using the `t()` function

### Scenario: Error cleared on cancel

- GIVEN an error is currently shown from a duplicate detection
- WHEN the user clicks cancel (cancelAdding or cancelEdit)
- THEN the `error` data property MUST be set to `null`
- AND the `NcNoteCard` MUST no longer be visible

---

## REQ-ASDP-004: Translation keys [MVP]

The error message MUST be translated using the `t()` function with keys present in both `l10n/en.json` and `l10n/nl.json`. No hardcoded strings are permitted per ADR-007.

### Scenario: English translation key present

- GIVEN the `l10n/en.json` file
- THEN it MUST contain the key `"An item with the name \"{name}\" already exists."`
- AND its value MUST be the identity string: `"An item with the name \"{name}\" already exists."`

### Scenario: Dutch translation present

- GIVEN the `l10n/nl.json` file
- THEN it MUST contain the same key `"An item with the name \"{name}\" already exists."`
- AND its Dutch value MUST be `"Er bestaat al een item met de naam \"{name}\"."`

### Scenario: t() call uses correct key

- GIVEN the TagManager component
- WHEN a duplicate is detected
- THEN the error MUST be set via `t('pipelinq', 'An item with the name "{name}" already exists.', { name })`
- AND the key MUST be English sentence case per ADR-007
