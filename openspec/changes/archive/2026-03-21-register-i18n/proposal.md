# Register Content Internationalization

## Problem
Enable multi-language support for Pipelinq's register objects, allowing users to view and manage CRM and pipeline content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`). This spec covers both data-level i18n for translatable register content (pipeline names, product descriptions) and app UI string translations via Nextcloud's `IL10N` / `t()` system per ADR-005.

## Proposed Solution
Implement Register Content Internationalization following the detailed specification. Key requirements include:
- Requirement: App UI MUST provide complete Dutch and English translations per ADR-005
- Requirement: CRM-specific terminology MUST use consistent Dutch translations
- Requirement: Translatable register content for pipelines and products MUST use language-keyed JSON
- Requirement: Translation key management MUST follow Nextcloud conventions
- Requirement: Plural forms MUST be handled correctly for Dutch and English

## Scope
This change covers all requirements defined in the register-i18n specification.

## Success Criteria
- Pipeline name supports multiple languages
- Fallback to Dutch when preferred language unavailable
- Switch language on pipeline detail page
- API returns content in requested language
- All Vue component strings use t() wrapper
