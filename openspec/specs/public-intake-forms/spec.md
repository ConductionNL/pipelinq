# public-intake-forms Specification

## Purpose
Provide embeddable HTML forms for external websites that create contacts and leads in Pipelinq upon submission. Forms are customizable in styling, support spam protection, and can be embedded via iframe or JavaScript snippet on any website.

## Context
Organizations need to capture leads from their website without requiring visitors to create accounts. Web-to-lead forms are the primary digital intake channel for CRM systems. For government context, this enables embedding contact/request forms on municipality websites that flow directly into Pipelinq pipelines for processing.

## ADDED Requirements

### Requirement: Form builder
The system MUST provide a no-code form builder for creating public intake forms.

#### Scenario: Create a basic contact form
- GIVEN a Pipelinq user with form management permissions
- WHEN they create a new form with fields: naam, email, telefoon, bericht
- THEN the form MUST be saved with a unique public URL
- AND each field MUST be configurable: label, type, required/optional, placeholder text

#### Scenario: Form field types
- GIVEN the form builder
- THEN the following field types MUST be supported:
  - Text (single line)
  - Textarea (multi-line)
  - Email (with validation)
  - Phone (with validation)
  - Select/dropdown (configurable options)
  - Checkbox
  - File upload (with size/type restrictions)
  - Hidden field (for tracking source/campaign)

#### Scenario: Map form fields to entity properties
- GIVEN a form with fields naam, email, bedrijf, vraag
- WHEN configuring the form's submission action
- THEN the user MUST map each form field to a contact or lead property
- AND unmapped fields MUST be stored in a "notes" or "custom fields" property on the created entity

### Requirement: Form submission creates CRM entities
Form submissions MUST create contacts and/or leads in Pipelinq.

#### Scenario: Submission creates contact + lead
- GIVEN a form configured to create both a contact and a lead
- WHEN a visitor submits the form with naam "Jan Bakker", email "jan@example.nl", bericht "Interesse in dienstverlening"
- THEN a Contact MUST be created (or matched to existing by email)
- AND a Lead MUST be created with title derived from the form name, linked to the contact
- AND the lead MUST be placed on the configured default pipeline and first stage

#### Scenario: Duplicate contact handling
- GIVEN an existing contact with email "jan@example.nl"
- WHEN a form submission arrives with the same email
- THEN the system MUST match to the existing contact (not create a duplicate)
- AND a new lead MUST be created linked to the existing contact

#### Scenario: Submission notification
- GIVEN a form with notification configured to user "maria"
- WHEN a submission is received
- THEN the assigned user MUST receive a Nextcloud notification
- AND optionally an email notification with submission details

### Requirement: Form embedding
Forms MUST be embeddable on external websites.

#### Scenario: Embed via iframe
- GIVEN a published form
- WHEN the user copies the embed code
- THEN an iframe HTML snippet MUST be provided
- AND the iframe MUST render the form with configurable width and height
- AND the form MUST be served over HTTPS

#### Scenario: Embed via JavaScript snippet
- GIVEN a published form
- THEN a JavaScript embed snippet MUST also be available
- AND the script MUST inject the form into a target DOM element
- AND the script MUST handle form submission via AJAX (no page reload)

#### Scenario: Custom styling
- GIVEN a form configuration screen
- WHEN the user customizes colors, fonts, and button styling
- THEN the public form MUST render with the custom styles
- AND a "Preview" button MUST show how the form will look embedded

### Requirement: Spam protection
Public forms MUST include spam protection mechanisms.

#### Scenario: Honeypot field
- GIVEN a published form
- THEN a hidden honeypot field MUST be included
- AND submissions with the honeypot field filled MUST be silently discarded

#### Scenario: Rate limiting
- GIVEN a public form endpoint
- WHEN more than 10 submissions arrive from the same IP within 5 minutes
- THEN subsequent submissions MUST be rejected with a 429 status
- AND the rate limit MUST be configurable per form

#### Scenario: Optional CAPTCHA
- GIVEN a form with CAPTCHA enabled
- THEN a CAPTCHA challenge (hCaptcha or Turnstile) MUST be presented before submission
- AND the CAPTCHA token MUST be verified server-side

### Requirement: Form management
The system MUST provide a management interface for forms.

#### Scenario: Form list
- WHEN the user navigates to the Forms management section
- THEN all forms MUST be listed with: name, status (active/inactive), submission count, created date
- AND each form MUST have actions: edit, preview, embed code, deactivate

#### Scenario: Submission history
- GIVEN a form with 50 submissions
- WHEN the user views the form's submission history
- THEN all submissions MUST be listed with: timestamp, submitter details, created entities
- AND the user MUST be able to export submissions as CSV

## Dependencies
- Pipelinq contact and lead entities (OpenRegister)
- Pipeline configuration (for default lead placement)
- Nextcloud notification system
- CORS configuration for cross-origin form submissions

---

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. There are no form builder components, public form endpoints, or embed code generation.

**Not yet implemented:**
- **Form builder:** No form builder UI or form entity/schema.
- **Form field types:** No configurable field type system.
- **Field-to-entity mapping:** No mechanism to map form fields to contact/lead properties.
- **Form submission creates CRM entities:** No public submission endpoint. No contact deduplication by email.
- **Form embedding:** No iframe or JavaScript embed snippet generation.
- **Custom styling:** No form style configuration.
- **Spam protection:** No honeypot field, rate limiting, or CAPTCHA integration.
- **Form management:** No form list, submission history, or CSV export.
- **Public API routes:** No public (non-authenticated) controller endpoints for form rendering or submission.
- **CORS configuration:** No cross-origin headers for external form submissions.

**Partial implementations:**
- None. This is a greenfield feature.

### Standards & References
- **CORS (Cross-Origin Resource Sharing):** Required for forms embedded on external websites.
- **hCaptcha / Cloudflare Turnstile:** CAPTCHA providers mentioned in the spec.
- **HTTPS:** All public form endpoints must be served over HTTPS.
- **GDPR/AVG:** Public forms collecting personal data must comply with privacy regulations. Not addressed in the spec.
- **Nextcloud public routes:** Nextcloud supports public (non-authenticated) controller routes via `#[PublicPage]` attribute.

### Specificity Assessment
- The spec provides a solid foundation but needs more detail for implementation.
- **Missing details:**
  - How are forms stored? As OpenRegister objects with a `form` schema, or via IAppConfig?
  - What is the public URL structure for forms? `/apps/pipelinq/public/forms/{formId}`?
  - How does the file upload field work for public submissions? Where are files stored?
  - What is the authentication model? Are forms truly public (no login required)?
  - How does CORS configuration work? Per-form allowed origins or global setting?
  - Should form submissions trigger n8n workflows?
- **Open questions:**
  - Should forms support multi-step wizards or only single-page forms?
  - How should the "duplicate contact handling" (matching by email) handle partial matches (same name, different email)?
  - What rate limiting storage backend should be used? APCu, database, or Redis?
  - Should forms support conditional field visibility (show field B only if field A equals X)?
