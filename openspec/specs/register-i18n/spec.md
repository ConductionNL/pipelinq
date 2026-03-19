# Register Content Internationalization

## Purpose
Enable multi-language support for Pipelinq's register objects, allowing users to view and manage CRM and pipeline content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).

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

### REQ-I18N-002: Language Fallback Chain
- MUST follow the Nextcloud user's language preference
- MUST fall back: user language -> app default language -> nl -> en -> first available
- MUST display fallback indicator when showing non-preferred language

### REQ-I18N-003: Frontend Language Switching
- MUST show language selector on detail pages when translated content exists
- MUST preserve current language selection across navigation within the app
- Language switching MUST NOT require page reload

### REQ-I18N-004: API Language Support
- API responses MUST accept `Accept-Language` header
- API responses MUST include `Content-Language` header
- `?lang=nl` query parameter MUST override Accept-Language
- Listing endpoints MUST return content in requested language with fallback

## Current Implementation Status
Not implemented. No multi-language content support exists in Pipelinq. All content is stored in a single language (typically Dutch). Pipeline definitions, stage names, and product catalogs are all single-language.

## Standards & References
- OpenRegister register-i18n spec (foundation)
- BCP 47 language tags (nl, en, de, fr, etc.)
- W3C Internationalization best practices
- Nextcloud l10n framework (for UI strings -- separate from register content i18n)
- WCAG 2.1 SC 3.1.1 (Language of Page) and SC 3.1.2 (Language of Parts)

## Specificity Assessment
Depends on OpenRegister's register-i18n being implemented first. App-level work is primarily frontend (language selector, fallback display) and API layer (Accept-Language routing). Pipelinq has a relatively small translation surface -- only structural/catalog objects need translation, not the CRM data (clients, leads, deals) which is user-generated.
