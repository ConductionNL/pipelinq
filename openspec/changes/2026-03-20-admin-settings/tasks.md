# Tasks: Admin Settings — Duplicate Prevention

## 0. Pre-implementation check

- [x] 0.1 Confirm that no server-side uniqueness constraint already exists for lead sources or request channel values in `lib/Controller/SettingsController.php` or equivalent — client-side check is sufficient for this scope.
- [x] 0.2 Verify that `TagManager.vue` already contains an `error` data property bound to an `NcNoteCard` error slot — no new template structure needed.
- [x] 0.3 Confirm that both `l10n/en.json` and `l10n/nl.json` exist and can receive the new translation key.

## 1. Frontend: TagManager.vue

- [x] 1.1 In `saveNew()`, after trimming the input and checking for empty, add case-insensitive duplicate guard:
  ```js
  const duplicate = this.tags.some(
    tag => tag.name.toLowerCase() === name.toLowerCase()
  )
  if (duplicate) {
    this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
    return
  }
  ```
  The guard MUST appear before the `this.error = null` / `this.$emit('add', name)` calls.

- [x] 1.2 In `saveRename(id)`, after trimming the input and checking for empty, add case-insensitive duplicate guard excluding the current tag:
  ```js
  const duplicate = this.tags.some(
    tag => tag.id !== id && tag.name.toLowerCase() === name.toLowerCase()
  )
  if (duplicate) {
    this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
    return
  }
  ```
  The `tag.id !== id` exclusion MUST be present — renaming to a casing variant of the tag's own name is allowed.

## 2. i18n

- [x] 2.1 Add the following entry to `l10n/en.json` (identity-mapped key = value per ADR-007):
  ```json
  "An item with the name \"{name}\" already exists.": "An item with the name \"{name}\" already exists."
  ```

- [x] 2.2 Add the following entry to `l10n/nl.json`:
  ```json
  "An item with the name \"{name}\" already exists.": "Er bestaat al een item met de naam \"{name}\"."
  ```

- [x] 2.3 Verify both files have exactly the same set of keys (no gaps) per ADR-007 requirements.

## 3. Verification

- [x] 3.1 Manual test — add duplicate: Open admin settings → Lead Sources → add `"telefonisch"` when it already exists → verify error appears and the list is unchanged.
- [x] 3.2 Manual test — add unique: Add `"beurs"` when it does not exist → verify it is added without error.
- [x] 3.3 Manual test — rename to duplicate: Rename any tag to the name of a different existing tag → verify error appears and the original name is retained.
- [x] 3.4 Manual test — rename to own name variant: Rename `"website"` to `"Website"` → verify the rename succeeds without error.
- [x] 3.5 Manual test — cancel clears error: Trigger a duplicate error, then click Cancel → verify the `NcNoteCard` disappears.
- [x] 3.6 Verify Dutch translation: Switch Nextcloud language to Nederlands and repeat duplicate add → verify the Dutch error message appears.
- [x] 3.7 Run translation key check: `grep -n 'already exists' l10n/en.json l10n/nl.json` → both files MUST contain the key.
- [x] 3.8 Run hardcoded string check: `grep -n 'already exists' src/views/settings/TagManager.vue` → MUST use `t()`, not a hardcoded string.
