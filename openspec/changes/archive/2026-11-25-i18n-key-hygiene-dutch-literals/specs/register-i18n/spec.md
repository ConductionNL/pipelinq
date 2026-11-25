## ADDED Requirements

### Requirement: i18n translation keys MUST be English source strings

Every `t('pipelinq', ...)` / `n('pipelinq', ...)` call site's literal key MUST be an English
source string, never Dutch (or any other non-English) prose. Locale files MUST map the English
key to a localized value for the corresponding locale — Dutch text belongs in `l10n/nl.json`'s
**value**, never as the key used in source.

#### Scenario: Dutch UI text is sourced from an English key

- **GIVEN** a component that previously called `t('pipelinq', 'Boekhoudkundige status')`
- **WHEN** the component is fixed per this change
- **THEN** the call site becomes `t('pipelinq', 'Bookkeeping status')`
- **AND** `l10n/nl.json` maps `"Bookkeeping status": "Boekhoudkundige status"`
- **AND** the rendered Dutch-locale UI text is unchanged from before the fix

@e2e exclude i18n key-hygiene refactor with no behavior change for the shipped Dutch locale;
verified by the locale-file reconciliation in tasks.md section 3 and a manual Dutch-locale
before/after comparison in tasks.md 4.2.

#### Scenario: Non-Dutch locales stop showing raw Dutch text

- **GIVEN** a call site whose key was Dutch prose with no corresponding `en.json`/`de.json`/etc.
  translation reachable through the correct key (because the key itself was Dutch)
- **WHEN** the component is fixed per this change
- **THEN** the English-locale UI shows the English translation instead of raw Dutch text
- **AND** `de`/`es`/`fr`/`it` locales show either a real translation or an English fallback,
  never the original Dutch string

@e2e exclude i18n key-hygiene refactor verified by manual English-locale comparison in tasks.md
4.3; no stable Playwright fixture exists for locale-switching across these specific POS/BRP/
project/kassakoppeling screens.
