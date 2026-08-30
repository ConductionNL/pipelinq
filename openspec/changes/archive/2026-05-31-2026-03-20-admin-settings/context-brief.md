# Proposal: Admin Settings — Duplicate Prevention

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer → Contacten

**Rationale:** Duplicate-prevention is a config concern; merge-action lives on contact-detail.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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
