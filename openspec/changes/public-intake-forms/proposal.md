# Proposal: public-intake-forms

## Problem

Pipelinq has no way to capture leads from external websites. There are no public form endpoints, no form builder, no embed code generation, and no spam protection. Organizations cannot create web-to-lead intake forms that feed into the CRM pipeline.

## Solution

Implement a public intake forms system with:
1. **IntakeForm schema** in OpenRegister for form definitions (fields, styling, target pipeline)
2. **IntakeSubmission schema** for recording all submissions
3. **PublicFormController** with public (no-auth) endpoints for rendering and submitting forms
4. **IntakeFormController** for managing forms (CRUD, embed code, submission history)
5. **IntakeFormService** for form rendering, submission processing, contact deduplication, and lead creation
6. **Form builder UI** for creating and managing intake forms
7. **Spam protection**: honeypot field, rate limiting
8. **Embed code** generation (iframe + JS snippet)

## Scope

- Form CRUD (create, edit, activate/deactivate, delete)
- Field types: text, textarea, email, phone, select, checkbox, file, hidden
- Field-to-entity property mapping
- Public form rendering endpoint (no auth required)
- Public form submission endpoint (creates contact + lead)
- Contact deduplication by email
- Honeypot spam protection
- Rate limiting per IP
- Embed code generation (iframe, JS snippet)
- Submission history with CSV export
- Form list management view

## Out of scope

- CAPTCHA integration (V1)
- Multi-step wizard forms (V1)
- Conditional field visibility (V1)
- Custom styling configuration (V1 -- basic styling only)
