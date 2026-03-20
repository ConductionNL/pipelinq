# Register Content Internationalization

## Purpose
Enable multi-language support for Pipelinq's register objects, allowing users to view and manage CRM and pipeline content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`). This spec covers both data-level i18n for translatable register content (pipeline names, product descriptions) and app UI string translations via Nextcloud's `IL10N` / `t()` system per ADR-005.

## Requirements

### REQ-I18N-001: Language-Tagged Fields
The following Pipelinq-specific fields MUST support multi-language content via OpenRegister's `translatable` flag:

**Pipelines:**
- `name` — display name of the pipeline (e.g., "Verkooppijplijn" / "Sales pipeline")
- `description` — explanation of the pipeline's purpose and workflow

**Pipeline stages:**
- `name` — display name of the stage (e.g., "Kwalificatie" / "Qualification")

**Products:**
- `name` — display name of the product or service
- `description` — product/service description shown to users

**Product categories:**
- `name` — display name of the category
- `description` — explanation of what this category contains

**NOT translatable:** Client names, contact details, lead notes, deal values, and other user-generated content MUST NOT be marked as translatable. This data is entered by users in their working language and does not require multi-language variants.

#### Scenario: Pipeline name supports multiple languages
- GIVEN a pipeline object with the `name` field marked as `translatable: true`
- WHEN an admin creates a pipeline with name `{"nl": "Verkooppijplijn", "en": "Sales pipeline"}`
- THEN the system MUST store the multi-language value
- AND display the name in the user's preferred language

### REQ-I18N-002: Language Fallback Chain
The system MUST follow the Nextcloud user's language preference with a defined fallback chain: user language -> app default language -> nl -> en -> first available. The system MUST display a fallback indicator when showing non-preferred language.

#### Scenario: Fallback to Dutch when preferred language unavailable
- GIVEN a user with preferred language set to French (fr)
- AND a pipeline name that has only nl and en translations
- WHEN the user views the pipeline
- THEN the system MUST display the Dutch (nl) translation as the fallback
- AND a fallback indicator MUST be shown

### REQ-I18N-003: Frontend Language Switching
The system MUST show a language selector on detail pages when translated content exists. The system MUST preserve current language selection across navigation within the app, and language switching MUST NOT require page reload.

#### Scenario: Switch language on pipeline detail page
- GIVEN a pipeline with name translations in nl and en
- WHEN the user clicks the language selector and chooses English
- THEN the pipeline name MUST update to the English translation without a page reload
- AND navigating to another page and back MUST preserve the English language selection

### REQ-I18N-004: API Language Support
API responses MUST accept `Accept-Language` header and MUST include `Content-Language` header. The `?lang=nl` query parameter MUST override Accept-Language. Listing endpoints MUST return content in requested language with fallback.

#### Scenario: API returns content in requested language
- GIVEN a product with name translations `{"nl": "Adviesgesprek", "en": "Consultation"}`
- WHEN an API client sends `GET /api/objects/{register}/{schema}` with `Accept-Language: en`
- THEN the response MUST return the product name as "Consultation"
- AND the response MUST include the header `Content-Language: en`

## ADDED Requirements

### Requirement: App UI MUST provide complete Dutch and English translations per ADR-005

All user-facing strings in Pipelinq MUST be wrapped in Nextcloud's translation system (`t('pipelinq', '...')` in Vue, `$this->l->t('...')` in PHP) and MUST have corresponding entries in both `l10n/en.json` and `l10n/nl.json`. Hardcoded user-facing strings are NOT allowed. The translation files MUST be kept in sync -- every key present in `en.json` MUST also be present in `nl.json` and vice versa.

#### Scenario: All Vue component strings use t() wrapper
- **GIVEN** a Vue component in Pipelinq displaying user-facing text
- **WHEN** the component renders a label, button text, placeholder, tooltip, or error message
- **THEN** the string SHALL be wrapped in `t('pipelinq', '...')` or `n('pipelinq', singular, plural, count)`
- **AND** a corresponding entry SHALL exist in `l10n/en.json` with the English source string as both key and value
- **AND** a corresponding entry SHALL exist in `l10n/nl.json` with the English source string as key and the Dutch translation as value

#### Scenario: PHP backend messages use IL10N
- **GIVEN** a PHP controller or service returning a user-facing message (success, error, validation)
- **WHEN** the message is constructed
- **THEN** it SHALL use `$this->l->t('...')` from Nextcloud's `IL10N` interface
- **AND** the string SHALL have entries in both `l10n/en.json` and `l10n/nl.json`

#### Scenario: Translation file parity check
- **GIVEN** the files `l10n/en.json` and `l10n/nl.json`
- **WHEN** a developer adds a new translation key to `en.json`
- **THEN** the same key MUST also be added to `nl.json` with the Dutch translation
- **AND** both files MUST have the same number of translation entries (currently ~395 keys each)
- **AND** no key in either file SHALL have an empty string as its value

### Requirement: CRM-specific terminology MUST use consistent Dutch translations

Pipelinq's CRM domain terminology MUST follow a consistent Dutch translation glossary to ensure uniformity across the app UI. These translations MUST align with VNG (Vereniging van Nederlandse Gemeenten) terminology where applicable for government CRM contexts.

#### Scenario: Core CRM entity translations are consistent
- **GIVEN** the CRM domain entities used throughout Pipelinq
- **WHEN** they appear in the Dutch UI
- **THEN** the following terminology mapping SHALL be applied consistently:
  - "Client" -> "Klant" (NOT "Cliënt")
  - "Clients" -> "Klanten"
  - "Contact" -> "Contactpersoon"
  - "Contacts" -> "Contactpersonen"
  - "Lead" -> "Lead" (borrowed term, no translation)
  - "Leads" -> "Leads"
  - "Request" -> "Verzoek"
  - "Requests" -> "Verzoeken"
  - "Pipeline" -> "Pipeline" (borrowed term, no translation)
  - "Stage" -> "Fase"
  - "Stages" -> "Fasen"
  - "Product" -> "Product"
  - "Products" -> "Producten"
  - "Deal" -> "Deal" (borrowed term)
  - "Assignee" -> "Toegewezen aan"
  - "Category" -> "Categorie"
  - "Channel" -> "Kanaal"
  - "Source" -> "Bron"
  - "Priority" -> "Prioriteit"
  - "Probability" -> "Waarschijnlijkheid"

#### Scenario: VNG-aligned government terminology
- **GIVEN** Pipelinq is used in Dutch government (gemeente) contexts
- **WHEN** CRM concepts overlap with VNG/Common Ground terminology
- **THEN** the following alignments SHALL be maintained:
  - "Convert to case" -> "Omzetten naar zaak" (zaak = VNG term for case)
  - "Customer requests" -> "Klantverzoeken"
  - "Prospect" -> "Prospect" (borrowed term)
  - "SBI Codes" -> "SBI codes" (Standaard Bedrijfsindeling)
  - "KVK" -> "KVK" (Kamer van Koophandel, no translation needed)

#### Scenario: Action verb translations are consistent
- **GIVEN** action buttons and menu items in the Pipelinq UI
- **WHEN** they appear in Dutch
- **THEN** the following verb translations SHALL be applied consistently:
  - "Add" -> "Toevoegen"
  - "Create" -> "Aanmaken"
  - "Edit" -> "Bewerken"
  - "Delete" -> "Verwijderen"
  - "Save" -> "Opslaan"
  - "Cancel" -> "Annuleren"
  - "Search" -> "Zoeken"
  - "Import" -> "Importeren"
  - "Remove" -> "Verwijderen"
  - "Assign" -> "Toewijzen"
  - "View" -> "Bekijken"

### Requirement: Translatable register content for pipelines and products MUST use language-keyed JSON

Pipeline names, stage names, product names, and product category names/descriptions that are stored as OpenRegister objects MUST use OpenRegister's `translatable` property mechanism. Translatable fields store values as language-keyed JSON (`{"nl": "...", "en": "..."}`), while non-translatable fields (client names, lead values, contact details) remain as simple values.

#### Scenario: Pipeline name stored with translations
- **GIVEN** a pipeline object with schema property `name` marked as `translatable: true`
- **WHEN** an admin creates a pipeline via the API with `{"name": {"nl": "Verkooppijplijn", "en": "Sales pipeline"}}`
- **THEN** the object JSON SHALL store `{"name": {"nl": "Verkooppijplijn", "en": "Sales pipeline"}}`
- **AND** when a Dutch-locale user views the pipeline list, they SHALL see "Verkooppijplijn"
- **AND** when an English-locale user views the pipeline list, they SHALL see "Sales pipeline"

#### Scenario: Stage names translated for Kanban board
- **GIVEN** a pipeline with stages having translatable `name` properties
- **WHEN** the Kanban board renders stage column headers
- **THEN** column headers SHALL display in the user's preferred language
- **AND** stage names like "Kwalificatie" / "Qualification", "Offerte" / "Quote", "Gewonnen" / "Won" SHALL resolve per the user's language

#### Scenario: Product catalog entries translated
- **GIVEN** a product with `name`: `{"nl": "Adviesgesprek", "en": "Consultation"}` and `description`: `{"nl": "Strategisch adviesgesprek van 1 uur", "en": "1-hour strategic consultation"}`
- **WHEN** the product is displayed in a lead's line items
- **THEN** both name and description SHALL appear in the user's preferred language
- **AND** the product's `price`, `sku`, and `taxRate` SHALL remain language-independent

#### Scenario: Client and contact data is NOT translatable
- **GIVEN** a client object with properties `tradeName`, `address`, `phone`, `email`
- **WHEN** these properties are inspected for `translatable` flag
- **THEN** all client/contact properties SHALL have `translatable: false` (or unset)
- **AND** the data SHALL be stored as simple values, not language-keyed JSON

### Requirement: Translation key management MUST follow Nextcloud conventions

Translation keys in `l10n/en.json` and `l10n/nl.json` MUST follow Nextcloud's translation key conventions. Keys are English source strings. The companion `.js` files (`en.js`, `nl.js`) MUST be auto-generated from the JSON files and kept in sync.

#### Scenario: Translation key format
- **GIVEN** the translation files `l10n/en.json` and `l10n/nl.json`
- **WHEN** a new translatable string is added
- **THEN** the key SHALL be the full English source string (e.g., `"Failed to save client. Please try again."`)
- **AND** the `en.json` value SHALL be identical to the key (English identity mapping)
- **AND** the `nl.json` value SHALL be the Dutch translation (e.g., `"Klant opslaan mislukt. Probeer het opnieuw."`)

#### Scenario: Parameterized translation strings
- **GIVEN** a UI string that includes dynamic values
- **WHEN** the translation key is defined
- **THEN** parameters SHALL use `{placeholder}` syntax (e.g., `"Last updated: {time}"` -> `"Laatst bijgewerkt: {time}"`)
- **AND** the placeholder names SHALL be identical in both the English and Dutch strings
- **AND** the Vue component SHALL pass the parameter: `t('pipelinq', 'Last updated: {time}', { time: formattedTime })`

#### Scenario: JS companion files stay in sync
- **GIVEN** the files `l10n/en.js` and `l10n/nl.js`
- **WHEN** the JSON translation files are updated
- **THEN** the JS files SHALL be regenerated to match
- **AND** the JS files SHALL use Nextcloud's `OC.L10N.register()` format for runtime loading

### Requirement: Plural forms MUST be handled correctly for Dutch and English

Translation strings involving counts MUST use Nextcloud's plural translation function (`n()` in Vue, `$this->l->n()` in PHP) to handle language-specific plural rules. Dutch and English both use two plural forms (singular for 1, plural for other counts), but the translated strings may differ structurally.

#### Scenario: Plural form for lead count
- **GIVEN** a dashboard widget showing lead count
- **WHEN** the count is 1
- **THEN** the UI SHALL display the singular form via `n('pipelinq', '{count} lead', '{count} leads', count, { count })`
- **AND** in Dutch: `"1 lead"` (singular), `"5 leads"` (plural)

#### Scenario: Plural form for overdue days
- **GIVEN** a lead that is overdue
- **WHEN** the overdue indicator is rendered
- **THEN** the UI SHALL use `n('pipelinq', 'day overdue', 'days overdue', days)` for correct pluralization
- **AND** in Dutch: `"dag te laat"` (singular), `"dagen te laat"` (plural)

#### Scenario: Plural forms in confirmation dialogs
- **GIVEN** a confirmation dialog for deleting pipeline stages
- **WHEN** the pipeline has multiple stages
- **THEN** the message SHALL use pluralization: `n('pipelinq', 'This pipeline has %n stage.', 'This pipeline has %n stages.', count)`
- **AND** in Dutch: `"Deze pipeline heeft %n fase."` / `"Deze pipeline heeft %n fasen."`

### Requirement: Date, number, and currency formatting MUST respect user locale

All date displays, number formatting, and currency values in Pipelinq MUST respect the user's Nextcloud locale settings. This includes lead values, product prices, due dates, and activity timestamps.

#### Scenario: Currency values follow locale
- **GIVEN** a lead with a pipeline value of 12500.50 EUR
- **WHEN** displayed to a Dutch-locale user
- **THEN** the value SHALL be formatted as `"€ 12.500,50"` (period as thousands separator, comma as decimal)
- **AND** when displayed to an English-locale user, it SHALL be formatted as `"€12,500.50"`

#### Scenario: Due dates follow locale format
- **GIVEN** a lead with due date `2026-03-20`
- **WHEN** displayed to a Dutch-locale user
- **THEN** the date SHALL be formatted as `"20-03-2026"` or `"20 maart 2026"`
- **AND** when displayed to an English-locale user, it SHALL be `"03/20/2026"` or `"March 20, 2026"`

#### Scenario: Relative time formatting
- **GIVEN** activity timestamps like "5 minutes ago"
- **WHEN** displayed in the activity feed
- **THEN** the translation SHALL use locale-appropriate strings: `"{minutes}m geleden"` (Dutch) vs `"{minutes}m ago"` (English)
- **AND** the existing translation keys `"{days}d ago"`, `"{hours}h ago"`, `"{minutes}m ago"` SHALL have Dutch counterparts

### Requirement: Translation completeness MUST be tracked and enforced

The Pipelinq translation files MUST maintain 100% coverage for both Dutch and English. Missing translations MUST be detectable through automated checks. Translation completeness for register content (translatable object properties) follows OpenRegister's completeness tracking spec.

#### Scenario: Detect missing Dutch translations
- **GIVEN** the files `l10n/en.json` and `l10n/nl.json`
- **WHEN** a completeness check is run
- **THEN** every key in `en.json` SHALL have a corresponding non-empty value in `nl.json`
- **AND** any key in `nl.json` whose value equals the English source string SHALL be flagged as potentially untranslated (false positives like "Dashboard", "Lead", "Pipeline" are acceptable as these are borrowed terms)

#### Scenario: New feature includes translations
- **GIVEN** a pull request adding a new UI feature with user-facing strings
- **WHEN** the PR is reviewed
- **THEN** both `l10n/en.json` and `l10n/nl.json` MUST be updated in the same PR
- **AND** the translation keys MUST be alphabetically sorted within the JSON file

#### Scenario: Register content translation completeness
- **GIVEN** a pipeline with translatable `name` property and register languages `["nl", "en"]`
- **WHEN** the pipeline has `name`: `{"nl": "Verkooppijplijn"}` but no English translation
- **THEN** the admin UI SHALL show a translation completeness indicator on the pipeline detail page
- **AND** the missing English translation SHALL be flagged in the register's translation dashboard (per OpenRegister's completeness tracking)

### Requirement: Translation export MUST support external review workflows

Pipelinq's UI translations (`l10n/*.json`) MUST be exportable in a format suitable for external translators or review by non-technical stakeholders. Register content translations follow OpenRegister's bulk export mechanism.

#### Scenario: Export UI translations for review
- **GIVEN** the files `l10n/en.json` and `l10n/nl.json`
- **WHEN** a translation coordinator needs to review Dutch translations
- **THEN** the JSON files SHALL be directly usable as review documents (key = English source, value = Dutch translation)
- **AND** the alphabetical sorting of keys SHALL make it easy to locate specific strings

#### Scenario: Export register content translations as CSV
- **GIVEN** pipeline objects with translatable properties and register languages `["nl", "en"]`
- **WHEN** an admin exports pipelines via `GET /api/objects/{register}/{schema}?_format=csv&_translations=all`
- **THEN** the CSV SHALL contain columns `name_nl`, `name_en`, `description_nl`, `description_en`
- **AND** the CSV SHALL be suitable for sending to a translator who can fill in missing values

#### Scenario: Import reviewed translations
- **GIVEN** a CSV file with completed translations reviewed by a translator
- **WHEN** the admin imports the CSV back into the register
- **THEN** the system SHALL detect the `_nl` and `_en` column suffixes and construct language-keyed objects
- **AND** existing translations SHALL be updated with the imported values

### Requirement: Fallback language chain MUST follow Nextcloud then register defaults

When a translation is missing for a user's preferred language, the system MUST follow a defined fallback chain. For UI strings, this follows Nextcloud's built-in locale resolution. For register content, this follows OpenRegister's configurable per-register fallback chain.

#### Scenario: UI string fallback
- **GIVEN** a Nextcloud user with language set to German (`de`)
- **WHEN** a Pipelinq UI string has no German translation (German `l10n/de.json` does not exist)
- **THEN** Nextcloud SHALL fall back to English (the source language in `en.json`)
- **AND** the UI SHALL display English strings without error

#### Scenario: Register content fallback for pipeline names
- **GIVEN** a register with languages `["nl", "en"]` and a pipeline with `name`: `{"nl": "Verkooppijplijn"}`
- **WHEN** an English-locale user views the pipeline
- **THEN** the system SHALL try English (not found), then Dutch (found), and display "Verkooppijplijn"
- **AND** the response SHALL include `X-Content-Language-Fallback: true` header
- **AND** the UI MAY show a subtle indicator that the displayed name is a fallback

#### Scenario: Complete fallback chain order
- **GIVEN** a register with languages `["nl", "en"]`
- **WHEN** content is requested in French (`Accept-Language: fr`)
- **THEN** the fallback chain SHALL be: `fr` (not available) -> `nl` (register default) -> `en` -> first available
- **AND** Dutch SHALL be returned as it is the register's default language

### Requirement: L10n file structure MUST follow Nextcloud app conventions

Pipelinq's translation files MUST maintain the standard Nextcloud app `l10n/` directory structure with paired JSON and JS files for each supported language.

#### Scenario: Required l10n file structure
- **GIVEN** the Pipelinq app directory
- **WHEN** the `l10n/` directory is inspected
- **THEN** it SHALL contain at minimum: `en.json`, `nl.json`, `en.js`, `nl.js`
- **AND** JSON files SHALL use the Nextcloud translation format: `{"translations": {"source string": "translated string"}}`
- **AND** JS files SHALL use `OC.L10N.register('pipelinq', {...})` format

#### Scenario: JSON translation file format
- **GIVEN** the file `l10n/nl.json`
- **WHEN** its structure is validated
- **THEN** it SHALL be valid JSON with a single top-level `"translations"` object
- **AND** keys SHALL be sorted alphabetically for maintainability
- **AND** no trailing commas or syntax errors SHALL be present

#### Scenario: Adding a new language
- **GIVEN** a community contributor wants to add French translations
- **WHEN** they create `l10n/fr.json` and `l10n/fr.js`
- **THEN** the files SHALL follow the same format as `nl.json` and `nl.js`
- **AND** Nextcloud SHALL automatically pick up the new language for users with French locale
- **AND** the app SHALL NOT require code changes to support the additional language

### Requirement: Notification and email strings MUST be translated

All notification texts and any email content generated by Pipelinq MUST be translatable and MUST use the recipient's Nextcloud language preference, not the sender's.

#### Scenario: Assignment notification in Dutch
- **GIVEN** a Dutch-locale user receiving a lead assignment notification
- **WHEN** the notification is generated
- **THEN** the notification text SHALL be in Dutch (e.g., "Lead aangemaakt vanuit {name}")
- **AND** the notification SHALL use `$this->l10nFactory->get('pipelinq', $recipientLanguage)` to resolve the recipient's language

#### Scenario: Status change notification respects recipient locale
- **GIVEN** a lead's stage changes from "Kwalificatie" to "Offerte"
- **WHEN** a notification is sent to an English-locale assignee
- **THEN** the notification text SHALL be in English
- **AND** if the stage name is translatable register content, it SHALL be resolved in the recipient's language

#### Scenario: Notification strings have translation keys
- **GIVEN** the notification strings in Pipelinq
- **WHEN** they are checked against `l10n/en.json` and `l10n/nl.json`
- **THEN** all notification templates SHALL have entries in both translation files
- **AND** the existing keys like `"Get notified when a lead or request is assigned to you."` -> `"Ontvang een melding wanneer een lead of verzoek aan u wordt toegewezen."` SHALL be maintained

### Requirement: Admin settings and configuration strings MUST be fully translated

All strings in the Pipelinq admin settings panel (configuration, pipeline settings, register configuration, ICP settings) MUST have Dutch and English translations.

#### Scenario: Admin settings page in Dutch
- **GIVEN** a Dutch-locale admin accessing Pipelinq settings
- **WHEN** the settings page renders
- **THEN** all section headers, labels, descriptions, and button texts SHALL appear in Dutch
- **AND** examples include: "Configureer uw Pipelinq installatie", "Pipeline instellingen", "Register configuratie", "ICP instellingen opslaan"

#### Scenario: Validation messages in admin settings
- **GIVEN** an admin submitting invalid configuration
- **WHEN** validation errors are displayed
- **THEN** error messages SHALL be in the admin's Nextcloud locale language
- **AND** examples include: "Pipeline titel is verplicht" (Dutch), "Pipeline title is required" (English)

#### Scenario: Configuration import/export messages translated
- **GIVEN** an admin performing configuration import
- **WHEN** the operation succeeds or fails
- **THEN** the feedback message SHALL be translated: "Configuratie succesvol opnieuw geïmporteerd" (Dutch) / "Configuration re-imported successfully" (English)

## Current Implementation Status
Partially implemented. App UI translations are in place with ~395 translation keys covering both Dutch (`l10n/nl.json`) and English (`l10n/en.json`), plus companion JS files (`en.js`, `nl.js`). CRM terminology is consistently translated (Client->Klant, Contact->Contactpersoon, Request->Verzoek, Stage->Fase, etc.). Parameterized strings use `{placeholder}` syntax correctly.

**Not implemented:**
- Multi-language register content (translatable pipeline names, stage names, product names/descriptions) -- depends on OpenRegister's register-i18n being implemented first
- Frontend language selector for content language switching
- Translation completeness tracking for register content
- Translation export for external review of register content
- Locale-aware number/currency formatting (values currently display raw numbers)
- Plural forms are partially used (some strings use manual singular/plural, not all use `n()`)

## Standards & References
- ADR-005: Internationalization -- Dutch and English Required (`openspec/architecture/adr-005-i18n-requirement.md`)
- OpenRegister register-i18n spec (foundation for data-level i18n)
- Nextcloud l10n framework (`IL10N`, `IFactory`, `t()`, `n()`)
- Nextcloud `@nextcloud/l10n` npm package for Vue frontend
- BCP 47 language tags (nl, en, de, fr, etc.)
- W3C Internationalization best practices
- WCAG 2.1 SC 3.1.1 (Language of Page) and SC 3.1.2 (Language of Parts)
- VNG terminology standards for Dutch government CRM contexts
- EU Single Digital Gateway (SDG) Regulation (EU) 2018/1724

## Specificity Assessment
Depends on OpenRegister's register-i18n being implemented first for data-level translations. App-level UI translations are already mature (~395 keys in nl/en). Remaining work is primarily: frontend language selector for register content, locale-aware formatting, plural form audit, and translation completeness tooling. Pipelinq has a relatively small translation surface for register content -- only structural/catalog objects (pipelines, stages, products, categories) need translation, not the CRM data (clients, leads, deals) which is user-generated.

## Cross-References
- `i18n-infrastructure` -- Vue frontend l10n setup (mixin, imports, directory structure)
- `i18n-string-extraction` -- Rules for wrapping translatable UI strings with `t()` / `$l->t()`
- `i18n-backend-messages` -- PHP controller/service message translation via `IL10N`
- `i18n-dutch-translations` -- Dutch translation completeness and terminology consistency
- `data-import-export` -- Import/export pipeline must handle translatable property columns
