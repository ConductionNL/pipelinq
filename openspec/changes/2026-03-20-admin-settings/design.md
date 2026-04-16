# Design: Admin Settings — Duplicate Prevention

## Overview
This implementation adds client-side duplicate prevention to the TagManager component, which is used for managing lead sources and request channels in the Pipelinq admin settings interface.

## Problem Statement
Users could add duplicate entries to TagManager (e.g., adding "website" twice) without any warning or validation, leading to confusing duplicate values in dropdown selectors.

## Solution
Implement case-insensitive duplicate detection in both add and rename operations, displaying a user-friendly error message that prevents the duplicate from being saved.

## Implementation Details

### Changes Made

#### 1. TagManager.vue Duplicate Detection (src/views/settings/TagManager.vue)
- **saveNew() method (lines 138-159)**: Added duplicate check that performs case-insensitive comparison against existing tags
  - Trims whitespace from input
  - Compares new name against all existing tags using `.toLowerCase()`
  - Displays error message if duplicate found
  - Prevents save if duplicate detected

- **saveRename() method (lines 173-194)**: Added duplicate check for rename operations
  - Excludes the currently edited tag from the comparison
  - Performs case-insensitive comparison
  - Prevents duplicate renamed values
  - Displays error message if duplicate found

#### 2. Translation Keys (l10n/en.json and l10n/nl.json)
- **Key**: `"An item with the name \"{name}\" already exists."`
- **English**: "An item with the name \"{name}\" already exists."
- **Dutch**: "Er bestaat al een item met de naam \"{name}\"."

The translation key uses Vue's translation function with parameter interpolation to display the duplicate name in the error message.

## User Experience
1. **When Adding**: User enters a name that matches an existing tag (case-insensitive). Clicking save or pressing Enter shows an error message with the conflicting name.
2. **When Renaming**: User attempts to rename a tag to a name that already exists. The component prevents the rename and displays the error message.
3. **Error Display**: Error messages appear in an NcNoteCard with error styling for clear visibility.

## Technical Details

### Comparison Logic
```javascript
// Case-insensitive comparison
const duplicate = this.tags.some(
  tag => tag.name.toLowerCase() === name.toLowerCase(),
)
```

For rename operations, the comparison excludes the current item:
```javascript
const duplicate = this.tags.some(
  tag => tag.id !== id && tag.name.toLowerCase() === name.toLowerCase(),
)
```

### Error Handling
- Error messages are displayed in the `error` data property
- Error clears when user starts a new add/edit operation
- Error prevents both programmatic save and propagation to parent component

## Testing Scope
Manual testing confirms:
- ✅ Adding duplicate with exact name match is prevented
- ✅ Adding duplicate with different casing (e.g., "Website" vs "website") is prevented
- ✅ Renaming to an existing name is prevented
- ✅ Renaming an item to its own name is allowed
- ✅ Error messages display correctly
- ✅ English and Dutch translations are available

## Acceptance Criteria Met
1. ✅ Duplicate name check added to `TagManager.saveNew()` with case-insensitive comparison
2. ✅ Duplicate name check added to `TagManager.saveRename()` excluding self-comparison
3. ✅ Translation keys provided for both English and Dutch
4. ✅ User receives clear error feedback when duplicates are attempted
5. ✅ No duplicates are persisted to the backend

## Files Modified
- `src/views/settings/TagManager.vue` — Duplicate detection logic and error handling
- `l10n/en.json` — English translation for duplicate error message
- `l10n/nl.json` — Dutch translation for duplicate error message

## Integration Points
- Parent components using TagManager remain unaffected
- Duplicate prevention is entirely client-side
- Parent receives `add` and `rename` events only on successful operations
- Failed operations (duplicates) do not emit events to parent

## Related Issues
- Issue #182: Admin Settings — Duplicate Prevention
