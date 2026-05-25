# Design: admin-settings-duplicate-prevention

## Architecture

### Data Layer

No new OpenRegister schemas or entities are introduced by this change. The `TagManager` component operates on tag arrays passed as props by its parent (the admin settings page). The duplicate check runs entirely in memory against the `tags` prop — no API calls, no new data model changes.

The `tags` prop has the shape:

```ts
[{ id: string, name: string }, ...]
```

This is an existing interface; no changes are needed to the data layer.

### Frontend

Single-component change: `src/views/settings/TagManager.vue`.

The component already exposes an `error` data property bound to an `NcNoteCard` error slot in the template. The duplicate detection logic is inserted as a guard inside the two mutation methods before the emit calls.

**`saveNew()` guard** (before emitting `add`):

```js
const duplicate = this.tags.some(
  tag => tag.name.toLowerCase() === name.toLowerCase()
)
if (duplicate) {
  this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
  return
}
```

**`saveRename()` guard** (before emitting `rename`):

```js
const duplicate = this.tags.some(
  tag => tag.id !== id && tag.name.toLowerCase() === name.toLowerCase()
)
if (duplicate) {
  this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
  return
}
```

The `tag.id !== id` exclusion allows renaming a tag to the same name with different casing (e.g., `"Website"` → `"website"` → allowed).

### Backend

No backend changes. The TagManager is a presentational component; its parent stores submit changes to the OpenRegister settings API. Preventing duplicates at the UI layer is sufficient for this scope.

### Integration Points

None. This change does not interact with external APIs, background jobs, or platform services.

## Components

See `specs/admin-settings-duplicate-prevention/spec.md` for detailed requirements and BDD scenarios.

## i18n

One new translation key added to both locale files:

| Key | English value | Dutch value |
|-----|--------------|-------------|
| `An item with the name "{name}" already exists.` | `An item with the name "{name}" already exists.` | `Er bestaat al een item met de naam "{name}".` |

Translation key follows ADR-007 sentence case. The `{name}` placeholder is interpolated by the `t()` call at runtime.

## Files Changed

### Modified Files

| File | Change |
|------|--------|
| `src/views/settings/TagManager.vue` | Add duplicate guard in `saveNew()` and `saveRename()` |
| `l10n/en.json` | Add `"An item with the name \"{name}\" already exists."` key |
| `l10n/nl.json` | Add Dutch translation for the same key |

### New Files

None.

## Seed Data

This change modifies no OpenRegister schemas and introduces no new entities, so no seed data is required.

For reference, the lead source and request channel values that TagManager manages are stored in the Pipelinq settings configuration. Example Dutch tag values that would be validated by this change:

| Context | Example tag values |
|---------|-------------------|
| Lead sources | `website`, `telefonisch`, `beurs`, `verwijzing`, `e-mail` |
| Request channels | `balie`, `telefoon`, `e-mail`, `post`, `chat`, `webformulier` |
