# Register Content Internationalization - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Language-Tagged Fields

Pipelinq-specific fields MUST support multi-language content via OpenRegister's translatable flag.

#### Scenario: Pipeline name supports multiple languages
- GIVEN a pipeline object with name marked as translatable
- WHEN an admin creates a pipeline with name {"nl": "Verkooppijplijn", "en": "Sales pipeline"}
- THEN the system MUST store the multi-language value and display in the user's preferred language

### Requirement: Language Fallback Chain

The system MUST follow user language preference with fallback: user lang -> app default -> nl -> en -> first available.

#### Scenario: Fallback to Dutch when preferred language unavailable
- GIVEN a user with French preference and a pipeline with only nl+en translations
- WHEN the user views the pipeline
- THEN the Dutch translation MUST be displayed with a fallback indicator

### Requirement: Frontend Language Switching

Detail pages MUST show a language selector when translated content exists.

#### Scenario: Switch language without page reload
- GIVEN a pipeline with nl and en translations
- WHEN the user clicks the language selector and chooses English
- THEN the pipeline name MUST update without page reload

### Requirement: API Language Support

API responses MUST support Accept-Language header and ?lang= query parameter.

#### Scenario: API returns content in requested language
- GIVEN a product with translations
- WHEN an API client sends GET with Accept-Language: en
- THEN the response MUST return English content with Content-Language: en header
