# Register Internationalization Specification

## Status: partial

## Purpose

Enable multi-language support for Pipelinq at both the app UI level and the register content level.

---

## Requirements

### Requirement: App UI MUST provide Dutch and English translations

**Status: implemented**

All user-facing strings MUST be wrapped in Nextcloud's translation system and have entries in both l10n/en.json and l10n/nl.json.

#### Scenario: All Vue component strings use t() wrapper
- GIVEN a Vue component displaying user-facing text
- WHEN the component renders labels, buttons, placeholders, tooltips, or error messages
- THEN the string MUST be wrapped in t('pipelinq', '...')
- AND corresponding entries MUST exist in l10n/en.json and l10n/nl.json

#### Scenario: PHP backend messages use IL10N
- GIVEN a PHP controller or service returning a user-facing message
- THEN it MUST use $this->l->t('...')
- AND the string MUST have entries in both translation files

---

## Unimplemented Requirements

The following requirements are tracked as a change proposal:

**Change:** `openspec/changes/register-content-i18n/`

- Multi-language register content (translatable pipeline names, stage names, product names/descriptions)
- Language-tagged fields via OpenRegister's translatable flag
- Language fallback chain (user lang -> app default -> nl -> en)
- Frontend language selector for content language switching
- API Accept-Language header and ?lang= parameter support
- Depends on OpenRegister's register-i18n foundation being implemented

---

### Implementation References

- `l10n/nl.json`, `l10n/en.json` -- ~395 translation keys
- `l10n/nl.js`, `l10n/en.js` -- companion JS translation files
