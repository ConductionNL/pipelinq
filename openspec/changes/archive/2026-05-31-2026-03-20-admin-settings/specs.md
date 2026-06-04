---
status: draft
---

# Specs: Admin Settings — Duplicate Prevention

**Feature tier**: MVP
**Spec refs**: `openspec/changes/2026-03-20-admin-settings/design.md`
**Standards**: WCAG 2.1 AA (error messaging), i18n (message translation)

---

## REQ-ADS-001: Prevent Duplicate Tag Names on Add

The TagManager component MUST prevent users from adding duplicate tag names. When a user attempts to add a new tag with a name that already exists (case-insensitive comparison), the operation MUST be blocked and an error message displayed. The error message MUST be user-visible via the existing error slot in the TagManager template.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/2026-03-20-admin-settings/design.md#Frontend`
**Files**: `src/views/settings/TagManager.vue`, `l10n/en.json`, `l10n/nl.json`

### Scenario REQ-ADS-001-01: Adding exact duplicate is blocked

- GIVEN the TagManager is configured with existing tag values `["website", "email", "phone"]`
- WHEN a user enters "website" in the input field and clicks Add
- THEN the add operation MUST NOT emit
- AND the component error state MUST be set to the duplicate message
- AND the tag list MUST remain unchanged (no "website" added twice)

### Scenario REQ-ADS-001-02: Adding case-variant duplicate is blocked

- GIVEN the TagManager is configured with existing tag values `["website", "email", "phone"]`
- WHEN a user enters "Website" (capitalized) in the input field and clicks Add
- THEN the add operation MUST NOT emit
- AND the component error state MUST be set to the duplicate message (using "Website" as the duplicate name in the message)
- AND the tag list MUST remain unchanged

### Scenario REQ-ADS-001-03: Adding unique tag succeeds

- GIVEN the TagManager is configured with existing tag values `["website", "email", "phone"]`
- WHEN a user enters "social-media" in the input field and clicks Add
- THEN the add operation MUST emit with the new tag name
- AND the error state MUST be cleared
- AND the parent component receives the add event and updates the tag list

### Scenario REQ-ADS-001-04: Error message is translated correctly

- GIVEN a user with Dutch locale viewing the admin settings
- WHEN they attempt to add a duplicate tag "telefoon" (which already exists)
- THEN the error message MUST display in Dutch: `"Er bestaat al een item met de naam \"telefoon\"."`
- AND the message MUST use the translated key from `l10n/nl.json`

### Scenario REQ-ADS-001-05: Error message includes the name causing conflict

- GIVEN a user attempts to add a duplicate tag "beurs" when it already exists
- WHEN the error message is displayed
- THEN the message MUST include "beurs" in the output
- AND the placeholder `{name}` MUST be interpolated with the actual tag name

---

## REQ-ADS-002: Prevent Duplicate Tag Names on Rename

The TagManager component MUST prevent users from renaming a tag to a name that already exists (case-insensitive comparison), with the exception that renaming a tag to a casing variant of its own name is allowed. When a user attempts an invalid rename, the operation MUST be blocked and an error message displayed.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/2026-03-20-admin-settings/design.md#Frontend`
**Files**: `src/views/settings/TagManager.vue`, `l10n/en.json`, `l10n/nl.json`

### Scenario REQ-ADS-002-01: Renaming to duplicate is blocked

- GIVEN the TagManager is configured with existing tag values `["website", "email", "phone"]`
- AND the user has selected the "email" tag for rename
- WHEN they change the name to "website" and confirm
- THEN the rename operation MUST NOT emit
- AND the component error state MUST be set to the duplicate message
- AND the tag MUST retain its original name "email"

### Scenario REQ-ADS-002-02: Renaming to own name with different casing is allowed

- GIVEN the TagManager is configured with existing tag values `["website", "Email", "phone"]`
- AND the user has selected the "website" tag for rename
- WHEN they change the name to "Website" (capitalized) and confirm
- THEN the rename operation MUST emit with the new name "Website"
- AND the error state MUST be cleared
- AND the tag MUST be updated to "Website"

### Scenario REQ-ADS-002-03: Renaming to new unique name succeeds

- GIVEN the TagManager is configured with existing tag values `["website", "email", "phone"]`
- AND the user has selected the "phone" tag for rename
- WHEN they change the name to "telefoon" and confirm
- THEN the rename operation MUST emit with the new name "telefoon"
- AND the error state MUST be cleared

### Scenario REQ-ADS-002-04: Renaming case-variant to duplicate is blocked

- GIVEN the TagManager is configured with existing tag values `["Website", "email", "phone"]`
- AND the user has selected the "email" tag for rename
- WHEN they change the name to "website" (lowercase, variant of existing "Website")
- THEN the rename operation MUST NOT emit
- AND the error message MUST be displayed

### Scenario REQ-ADS-002-05: Error message on rename uses correct plural/singular

- GIVEN a user attempts to rename a tag to a duplicate
- WHEN the error message is displayed
- THEN the message MUST read "An item with the name \"{name}\" already exists." (singular)
- AND the message MUST NOT use plural forms or different wording than the add scenario

---

## REQ-ADS-003: Duplicate Check Applies to Both Lead Sources and Request Channels

The duplicate prevention logic implemented in TagManager MUST apply uniformly to all uses of the component, including lead source tags and request channel tags in the admin settings.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/2026-03-20-admin-settings/design.md#Frontend`
**Files**: `src/views/settings/TagManager.vue`

### Scenario REQ-ADS-003-01: Duplicate check on lead sources

- GIVEN the admin settings page displays the lead sources TagManager
- WHEN a user attempts to add a duplicate lead source (e.g., "website" when it already exists)
- THEN the duplicate check MUST block the add
- AND the error message MUST appear in the lead sources section

### Scenario REQ-ADS-003-02: Duplicate check on request channels

- GIVEN the admin settings page displays the request channels TagManager
- WHEN a user attempts to add a duplicate request channel (e.g., "balie" when it already exists)
- THEN the duplicate check MUST block the add
- AND the error message MUST appear in the request channels section

### Scenario REQ-ADS-003-03: Independent error state per TagManager instance

- GIVEN the admin settings page displays both lead sources and request channels TagManager instances
- WHEN a user triggers a duplicate error in the lead sources TagManager
- THEN the request channels TagManager error state MUST remain clear
- AND dismissing the error in lead sources MUST NOT affect request channels

---

## REQ-ADS-004: Error Clearing and User Recovery

When an error is displayed, the user MUST be able to recover by either fixing the input or dismissing the error. The error state MUST be managed appropriately during the user interaction flow.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/2026-03-20-admin-settings/design.md#Frontend`
**Files**: `src/views/settings/TagManager.vue`

### Scenario REQ-ADS-004-01: Clearing error by changing input

- GIVEN a duplicate error is displayed ("An item with the name \"website\" already exists.")
- WHEN the user modifies the input field to a unique name (e.g., "social-media")
- THEN the error message SHOULD remain visible until the user attempts Add again (consistent with form UX)
- AND when the user clicks Add with the unique name, the error MUST clear and the add MUST succeed

### Scenario REQ-ADS-004-02: Cancel button clears error

- GIVEN a duplicate error is displayed
- WHEN the user clicks Cancel or dismisses the edit form
- THEN the error state MUST be cleared
- AND the TagManager MUST return to its ready state

### Scenario REQ-ADS-004-03: Error clears on successful operation

- GIVEN a duplicate error was previously displayed
- WHEN the user corrects the input to a unique value and successfully adds/renames
- THEN the error state MUST be cleared
- AND subsequent operations start with a clean error state
