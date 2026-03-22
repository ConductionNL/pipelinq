# Register Content Internationalization

## Problem
App UI translations are in place (~395 keys, nl+en), but multi-language register content does not exist. Pipeline names, stage names, product names/descriptions cannot be stored in multiple languages. No frontend language selector for content switching. Depends on OpenRegister's register-i18n being implemented first.

## Current State (Implemented)
- ~395 translation keys in l10n/nl.json and l10n/en.json
- CRM terminology consistently translated (Client->Klant, Contact->Contactpersoon, etc.)
- Parameterized strings use {placeholder} syntax correctly

## Proposed Solution
Mark specific fields as translatable (pipeline name, stage name, product name, product description, category name/description). Implement language fallback chain (user lang -> app default -> nl -> en). Build frontend language selector. Add API Accept-Language header support. Depends on OpenRegister's register-i18n foundation.

## Impact
- Depends on: OpenRegister register-i18n implementation
- Update pipelinq_register.json with translatable field markers
- Build language selector component
- Add API language header support
- Frontend language switching without page reload
