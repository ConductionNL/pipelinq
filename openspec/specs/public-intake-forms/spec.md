# public-intake-forms Specification

## Purpose
Provide embeddable HTML forms for external websites that create contacts and leads in Pipelinq upon submission. Forms are customizable in styling, support spam protection, and can be embedded via iframe or JavaScript snippet on any website.

## Context
Organizations need to capture leads from their website without requiring visitors to create accounts. Web-to-lead forms are the primary digital intake channel for CRM systems. For government context, this enables embedding contact/request forms on municipality websites that flow directly into Pipelinq pipelines for processing.

**Competitive landscape:** Krayin CRM provides web-to-lead forms with selectable attributes and customizable styling (colors, labels, button text), but lacks CAPTCHA or spam protection. EspoCRM offers Lead Capture forms via its Advanced Pack with webhook-based lead creation. Both competitors provide basic single-page forms. Pipelinq can differentiate with government-specific features: AVG-compliant forms, NL Design System styling, and request creation alongside lead creation.

**Tender relevance:** Formulieren/intake appears in 61% of government tenders (42/69). The combination with klantinteractie (65%) makes public intake a critical entry point for citizen service workflows.

## Requirements

---

### Requirement: Form Builder
The system MUST provide a no-code form builder for creating public intake forms.

**Feature tier**: MVP

#### Scenario: Create a basic contact form
- GIVEN a Pipelinq user with form management permissions (admin)
- WHEN they navigate to Settings > Formulieren > Nieuw
- THEN a form builder MUST display with:
  - Form name (required)
  - Form description (optional, shown as intro text on the public form)
  - Add field button to add form fields
  - Preview panel showing the form as it will appear publicly
- AND the form MUST be saved with a unique public URL slug (auto-generated from name, editable)

#### Scenario: Configure form fields
- GIVEN the form builder
- WHEN the user adds a field
- THEN each field MUST be configurable with:
  - Label (displayed to the form visitor)
  - Field type (see supported types below)
  - Required/optional toggle
  - Placeholder text
  - Help text (shown below the field)
  - Validation rules (min/max length, pattern for text fields)
- AND fields MUST be reorderable via drag-and-drop

#### Scenario: Supported form field types
- GIVEN the form builder
- THEN the following field types MUST be supported:
  - **Text** (single line) -- with optional min/max length validation
  - **Textarea** (multi-line) -- with optional max character count
  - **Email** -- with built-in email format validation
  - **Phone** -- with built-in Dutch phone format validation (+31/06 prefix)
  - **Select/dropdown** -- configurable options list
  - **Radio buttons** -- configurable options list (single selection)
  - **Checkbox** -- single boolean toggle
  - **Checkbox group** -- multiple selectable options
  - **Date** -- date picker (HTML5 date input)
  - **File upload** -- with configurable max size (default 10 MB) and allowed types (default: pdf, jpg, png, docx)
  - **Hidden field** -- for tracking source/campaign/utm parameters (not visible to visitor)

#### Scenario: Form field conditional visibility
- GIVEN the form builder
- WHEN the user configures a field
- THEN they MUST be able to set a visibility condition:
  - "Show this field only when [other field] equals [value]"
  - "Show this field only when [other field] is not empty"
- AND the conditional logic MUST evaluate client-side (JavaScript) for immediate response
- AND hidden fields MUST NOT be submitted or validated

---

### Requirement: Field-to-Entity Mapping
Form submissions MUST be mappable to Pipelinq entity properties via configurable field mapping.

**Feature tier**: MVP

#### Scenario: Map form fields to contact properties
- GIVEN a form with fields naam, email, telefoon, bedrijf
- WHEN configuring the form's submission mapping
- THEN the user MUST be able to map each field to a contact property:
  - naam -> contact.name
  - email -> contact.email
  - telefoon -> contact.phone
  - bedrijf -> client.name (creates or matches client)
- AND the mapping UI MUST show all available properties from the contact and client schemas in OpenRegister

#### Scenario: Map form fields to lead properties
- GIVEN a form configured to create leads
- WHEN configuring the lead mapping
- THEN the user MUST be able to map fields to lead properties:
  - onderwerp -> lead.title
  - budget -> lead.value
  - urgentie -> lead.priority
- AND the user MUST select the target pipeline and initial stage for created leads

#### Scenario: Map form fields to request properties
- GIVEN a form configured to create requests (government use case)
- WHEN configuring the request mapping
- THEN the user MUST be able to map fields to request properties:
  - onderwerp -> request.title
  - toelichting -> request.description
  - categorie -> request.category
- AND the channel on the created request MUST be auto-set to "website"
- AND the request status MUST be auto-set to the first status (e.g., "new")

#### Scenario: Unmapped fields stored as notes
- GIVEN a form with fields naam, email, vraag, opmerkingen
- AND only naam and email are mapped to contact properties
- WHEN a submission is received
- THEN the unmapped fields (vraag, opmerkingen) MUST be stored as a formatted note on the created entity
- AND the note MUST include field labels and values: "Vraag: Hoe vraag ik een vergunning aan?\nOpmerkingen: Graag spoedbehandeling"

---

### Requirement: Form Submission Creates CRM Entities
Form submissions MUST create contacts and/or leads/requests in Pipelinq via the OpenRegister API.

**Feature tier**: MVP

#### Scenario: Submission creates contact + lead
- GIVEN a form configured to create both a contact and a lead
- WHEN a visitor submits the form with naam "Jan Bakker", email "jan@example.nl", bericht "Interesse in dienstverlening"
- THEN a Contact MUST be created (or matched to existing by email) via OpenRegister
- AND a Lead MUST be created with title derived from the form name, linked to the contact
- AND the lead MUST be placed on the configured default pipeline and first stage
- AND the lead source MUST be auto-set to "website"

#### Scenario: Submission creates contact + request
- GIVEN a form configured to create both a contact and a request (government use case)
- WHEN a visitor submits the form with naam "Fatima Yilmaz", email "fatima@example.nl", onderwerp "Parkeervergunning aanvragen"
- THEN a Contact MUST be created (or matched to existing by email) via OpenRegister
- AND a Request MUST be created with title from the onderwerp field, linked to the contact
- AND the request channel MUST be auto-set to "website"

#### Scenario: Duplicate contact handling by email
- GIVEN an existing contact with email "jan@example.nl"
- WHEN a form submission arrives with the same email
- THEN the system MUST match to the existing contact (not create a duplicate)
- AND a new lead/request MUST be created linked to the existing contact
- AND the existing contact's details MUST NOT be overwritten (unless the form is configured to update)

#### Scenario: Duplicate contact handling by phone
- GIVEN an existing contact with phone "+31612345678"
- AND no contact with email "new@example.nl"
- WHEN a form submission arrives with email "new@example.nl" and phone "+31612345678"
- THEN the system MUST create a new contact (email is the primary dedup key)
- AND a warning MUST be logged that a potential duplicate exists based on phone number

#### Scenario: Submission notification to assigned user
- GIVEN a form with notification configured to user "maria"
- WHEN a submission is received
- THEN maria MUST receive a Nextcloud notification: "Nieuw formulierinzending: [form name] - [contact name]"
- AND the notification MUST link to the created lead/request detail page
- AND optionally, if email notification is enabled, maria MUST also receive an email with submission details

#### Scenario: Submission triggers automation
- GIVEN a form configured to create leads
- AND an active automation with trigger "Lead created" and condition "source = website"
- WHEN a form submission creates a new lead
- THEN the lead creation MUST trigger the CRM automation system (per crm-workflow-automation spec)
- AND the automation actions MUST execute (e.g., auto-assign, send welcome email)

---

### Requirement: Form Embedding
Forms MUST be embeddable on external websites via multiple embedding methods.

**Feature tier**: MVP

#### Scenario: Embed via iframe
- GIVEN a published form with slug "contact-formulier"
- WHEN the user copies the embed code from the form management page
- THEN an iframe HTML snippet MUST be provided:
  ```html
  <iframe src="https://[nextcloud-host]/apps/pipelinq/public/forms/contact-formulier" width="100%" height="600" frameborder="0"></iframe>
  ```
- AND the iframe MUST render the form styled according to the form's configuration
- AND the form MUST be served over HTTPS
- AND the iframe height MUST auto-adjust to form content (via postMessage)

#### Scenario: Embed via JavaScript snippet
- GIVEN a published form with slug "contact-formulier"
- THEN a JavaScript embed snippet MUST also be available:
  ```html
  <div id="pipelinq-form-contact-formulier"></div>
  <script src="https://[nextcloud-host]/apps/pipelinq/public/forms/contact-formulier/embed.js"></script>
  ```
- AND the script MUST inject the form into the target DOM element
- AND the script MUST handle form submission via AJAX (no page reload)
- AND the script MUST be lightweight (< 50 KB minified)

#### Scenario: Direct link to form
- GIVEN a published form with slug "contact-formulier"
- THEN the form MUST also be accessible via direct URL: `https://[nextcloud-host]/apps/pipelinq/public/forms/contact-formulier`
- AND the direct URL MUST render a standalone HTML page with the form
- AND the page MUST include the form name, description, and a footer with organization name

#### Scenario: CORS configuration for cross-origin embedding
- GIVEN a form embedded on https://www.gemeente.nl
- WHEN the form submits data to the Pipelinq backend
- THEN the server MUST include CORS headers allowing the embedding origin
- AND CORS origins MUST be configurable per form (list of allowed domains)
- AND if no origins are configured, CORS MUST default to allowing all origins (for development/testing)

---

### Requirement: Custom Styling
Forms MUST support visual customization to match the embedding website's branding.

**Feature tier**: V1

#### Scenario: Configure form colors and fonts
- GIVEN a form configuration screen
- WHEN the user customizes styling
- THEN the following style options MUST be available:
  - Primary color (used for buttons and accents)
  - Background color
  - Text color
  - Font family (select from web-safe fonts + system fonts)
  - Border radius (rounded vs. square corners)
  - Button label text (default: "Verzenden")
- AND a live preview MUST show how the form will look with the applied styles

#### Scenario: NL Design System token support
- GIVEN a municipality embedding the form on their government website
- WHEN the form admin enables "NL Design System" mode
- THEN the form MUST use CSS custom properties (design tokens) from the NL Design System
- AND the form MUST inherit the municipality's theme tokens when embedded on their site
- AND fallback values MUST be provided for when tokens are not available

#### Scenario: Custom CSS injection
- GIVEN an advanced user configuring form styling
- WHEN they enable "Aangepaste CSS"
- THEN a CSS editor MUST be available for entering custom CSS rules
- AND the custom CSS MUST be scoped to the form container (preventing style leaks)
- AND the CSS MUST be sanitized to prevent XSS (no `url()`, `expression()`, or `@import` with external URLs)

#### Scenario: Responsive form layout
- GIVEN a published form
- WHEN viewed on a mobile device (viewport < 768px)
- THEN the form MUST render in a single-column layout
- AND all form elements MUST be touch-friendly (minimum 44px tap targets)
- AND file upload MUST support mobile camera capture (accept="image/*;capture=camera")

---

### Requirement: Spam Protection
Public forms MUST include multiple layers of spam protection.

**Feature tier**: MVP

#### Scenario: Honeypot field
- GIVEN a published form
- THEN a hidden honeypot field MUST be included (hidden via CSS, not `type="hidden"`)
- AND the honeypot field MUST have a realistic name (e.g., "website" or "company_url")
- AND submissions with the honeypot field filled MUST be silently discarded (200 response, no entity created)
- AND discarded submissions MUST be logged for monitoring

#### Scenario: Time-based bot detection
- GIVEN a published form
- THEN a hidden timestamp field MUST record when the form was loaded
- AND submissions completed in less than 3 seconds MUST be flagged as suspicious
- AND flagged submissions MUST be logged but still processed (soft detection, not rejection)

#### Scenario: Rate limiting per IP
- GIVEN a public form endpoint
- WHEN more than 10 submissions arrive from the same IP within 5 minutes
- THEN subsequent submissions MUST be rejected with HTTP 429 (Too Many Requests)
- AND the response MUST include a `Retry-After` header
- AND the rate limit MUST be configurable per form (default: 10 per 5 minutes)
- AND rate limiting MUST use APCu for storage (consistent with OpenRegister's rate limiting)

#### Scenario: Optional CAPTCHA integration
- GIVEN a form with CAPTCHA enabled
- THEN the form MUST support one of the following CAPTCHA providers:
  - **hCaptcha** (privacy-focused, GDPR-compliant)
  - **Cloudflare Turnstile** (invisible, user-friendly)
- AND the CAPTCHA widget MUST be rendered in the form before the submit button
- AND the CAPTCHA token MUST be verified server-side before processing the submission
- AND the CAPTCHA site key and secret key MUST be configurable in form settings

#### Scenario: CAPTCHA fallback for accessibility
- GIVEN a form with CAPTCHA enabled
- AND a visitor using a screen reader or assistive technology
- THEN the CAPTCHA provider MUST support an accessible alternative (audio challenge or accessible checkbox)
- AND the form MUST remain functional with CAPTCHA enabled (WCAG AA compliance)

---

### Requirement: Form Success and Error Handling
The system MUST provide configurable success and error responses for form submissions.

**Feature tier**: MVP

#### Scenario: Configurable success message
- GIVEN a form configuration screen
- WHEN the user configures the success response
- THEN they MUST be able to choose between:
  - **Success message** (default): display a configurable text message (default: "Bedankt voor uw bericht. We nemen zo snel mogelijk contact met u op.")
  - **Redirect URL**: redirect to a custom URL after successful submission
- AND the success message MUST support basic HTML formatting (bold, links, line breaks)

#### Scenario: Validation error display
- GIVEN a form with required fields naam and email
- WHEN a visitor submits with naam empty and invalid email
- THEN inline validation errors MUST appear below each invalid field
- AND the errors MUST be in Dutch: "Dit veld is verplicht", "Voer een geldig e-mailadres in"
- AND the form MUST NOT be submitted until all validation passes
- AND validation MUST occur both client-side (immediate) and server-side (on submit)

#### Scenario: Server error handling
- GIVEN a form submission where the OpenRegister API is unavailable
- WHEN the submission fails to create entities
- THEN the visitor MUST see a user-friendly error message: "Er is iets misgegaan. Probeer het later opnieuw."
- AND the submission data MUST be stored in a retry queue (IAppConfig or database)
- AND the admin MUST receive a notification about the failed submission

---

### Requirement: Form Management
The system MUST provide a management interface for all forms.

**Feature tier**: MVP

#### Scenario: Form list view
- WHEN the user navigates to Settings > Formulieren
- THEN all forms MUST be listed with: name, slug, status (active/inactive), submission count, created date, last submission date
- AND each form MUST have actions: edit, preview, copy embed code, activate/deactivate, duplicate, delete
- AND the list MUST be sortable by name and submission count

#### Scenario: Activate/deactivate form
- GIVEN a published form that is currently active
- WHEN the admin deactivates the form
- THEN the public form URL MUST return a 410 Gone response with message "Dit formulier is niet meer beschikbaar"
- AND the embed code MUST show the same message
- AND existing submission data MUST remain accessible in the management interface

#### Scenario: Duplicate form
- GIVEN an existing form "Contact Formulier"
- WHEN the admin clicks "Dupliceren"
- THEN a new form MUST be created with name "Contact Formulier (kopie)"
- AND all fields, mappings, styling, and configuration MUST be copied
- AND the new form MUST be created in inactive state
- AND the slug MUST be auto-generated as "contact-formulier-kopie" (unique)

---

### Requirement: Submission History and Export
The system MUST track all form submissions and provide export capabilities.

**Feature tier**: V1

#### Scenario: Submission history list
- GIVEN a form with 50 submissions
- WHEN the admin views the form's submission history
- THEN all submissions MUST be listed with: timestamp, submitter name, submitter email, created entities (contact/lead/request with links), status (success/failed)
- AND the list MUST be paginated (25 per page)
- AND the list MUST be sortable by timestamp

#### Scenario: View submission details
- GIVEN a submission in the history list
- WHEN the admin clicks on the submission
- THEN all submitted field values MUST be displayed
- AND links to created entities (contact, lead, request) MUST be clickable
- AND the submission's IP address, user agent, and timestamp MUST be shown (for spam investigation)

#### Scenario: Export submissions as CSV
- GIVEN a form with submissions
- WHEN the admin clicks "Exporteren als CSV"
- THEN a CSV file MUST be generated with:
  - One row per submission
  - Columns: timestamp, all form field labels as headers, created entity IDs
  - Dutch date format (dd-mm-yyyy HH:mm)
  - UTF-8 encoding with BOM (for Excel compatibility)
- AND the export MUST respect any active date range filter

#### Scenario: Delete old submissions
- GIVEN submissions older than a configurable retention period (default: 365 days)
- THEN the system MUST support manual deletion of submissions by date range
- AND a scheduled cleanup MUST be configurable to auto-delete submissions older than the retention period
- AND deletion MUST only remove the submission record, not the created entities (contacts, leads, requests)

---

### Requirement: Public Form API Routes
The system MUST provide public (non-authenticated) API routes for form rendering and submission.

**Feature tier**: MVP

#### Scenario: Public form rendering endpoint
- GIVEN a published form with slug "contact-formulier"
- WHEN a GET request is made to `/apps/pipelinq/public/forms/contact-formulier`
- THEN the response MUST be a complete HTML page with the rendered form
- AND the response MUST NOT require Nextcloud authentication (using `#[PublicPage]` attribute)
- AND the response MUST include appropriate security headers (X-Frame-Options: ALLOWALL for embedding, CSP with form-action self)

#### Scenario: Public form submission endpoint
- GIVEN a published form with slug "contact-formulier"
- WHEN a POST request is made to `/apps/pipelinq/public/forms/contact-formulier/submit`
- THEN the endpoint MUST accept multipart/form-data (for file uploads) or application/json
- AND the endpoint MUST NOT require Nextcloud authentication
- AND the endpoint MUST validate all fields against the form's configuration
- AND on success, the endpoint MUST return JSON: `{"success": true, "message": "Bedankt voor uw bericht."}`
- AND on validation error, MUST return JSON: `{"success": false, "errors": {"email": "Voer een geldig e-mailadres in"}}`

#### Scenario: Public embed JavaScript endpoint
- GIVEN a published form with slug "contact-formulier"
- WHEN a GET request is made to `/apps/pipelinq/public/forms/contact-formulier/embed.js`
- THEN the response MUST be a JavaScript file that renders the form in the target element
- AND the script MUST use Shadow DOM to isolate form styles from the host page
- AND the response MUST have `Content-Type: application/javascript` and be cacheable (1 hour)

#### Scenario: File upload endpoint for public forms
- GIVEN a form with a file upload field
- WHEN a visitor uploads a file via the public form
- THEN the file MUST be stored in Nextcloud Files under a configurable folder (default: `/Pipelinq/Formulieren/[form-slug]/`)
- AND the file MUST be owned by the configured service account (not the anonymous visitor)
- AND file size MUST be validated against the field's max size configuration
- AND file type MUST be validated against the field's allowed types list
- AND the file path MUST be stored as a reference on the created entity

---

### Requirement: Form Data Storage
Form configurations and submissions MUST be stored as OpenRegister objects.

**Feature tier**: MVP

#### Scenario: Form schema definition
- GIVEN the Pipelinq register in OpenRegister
- THEN a `form` schema MUST be defined with the following properties:
  - `title` (string, required): form display name
  - `slug` (string, required, unique): URL-friendly identifier
  - `description` (string, optional): intro text shown on the form
  - `active` (boolean, required): whether the form accepts submissions
  - `fields` (array, required): ordered list of field definitions `[{label, type, required, placeholder, helpText, validation, conditions}]`
  - `entityMapping` (object, required): `{createContact, createLead, createRequest, fieldMappings: [{formField, entityField}], targetPipeline, targetStage}`
  - `styling` (object, optional): `{primaryColor, backgroundColor, textColor, fontFamily, borderRadius, buttonLabel, customCss, nlDesignMode}`
  - `spamProtection` (object, optional): `{honeypot, rateLimit, captchaProvider, captchaSiteKey, captchaSecretKey}`
  - `successConfig` (object, optional): `{type: "message"|"redirect", message, redirectUrl}`
  - `corsOrigins` (array, optional): list of allowed embedding origins
  - `notifyUsers` (array, optional): user IDs to notify on submission
- AND the schema MUST be added to `lib/Settings/pipelinq_register.json`

#### Scenario: Submission log schema definition
- GIVEN the Pipelinq register in OpenRegister
- THEN a `formSubmission` schema MUST be defined with:
  - `formId` (string, required): reference to the form
  - `submittedAt` (datetime, required): submission timestamp
  - `fieldValues` (object, required): key-value map of submitted field values
  - `ipAddress` (string, optional): submitter's IP (hashed for AVG compliance)
  - `userAgent` (string, optional): browser user agent
  - `createdEntities` (object, optional): `{contactId, leadId, requestId}`
  - `status` (string, required): "success" | "failed" | "spam"
  - `errorMessage` (string, optional): error details if failed

#### Scenario: AVG/GDPR compliance for stored submissions
- GIVEN form submissions containing personal data
- THEN IP addresses MUST be stored as one-way hashes (not plain text)
- AND submission data MUST be deletable via the submission management interface
- AND a data retention policy MUST be configurable per form (default: 365 days)
- AND when a contact requests data deletion (AVG verwijderverzoek), all linked form submissions MUST be identifiable and deletable

---

### Requirement: Form Analytics
The system MUST provide analytics for form performance monitoring.

**Feature tier**: V1

#### Scenario: Form submission statistics
- GIVEN a form active for 30 days with 200 submissions
- WHEN the admin views the form's analytics
- THEN the following metrics MUST be displayed:
  - Total submissions (200)
  - Submissions per day (time series chart)
  - Success rate (submissions that created entities vs. total)
  - Spam rate (submissions flagged/rejected as spam)

#### Scenario: Conversion tracking
- GIVEN a form creating leads in a sales pipeline
- WHEN the admin views conversion analytics
- THEN the system MUST show:
  - Total leads created from this form
  - Leads that progressed past the first stage
  - Leads that reached "Won" stage
  - Total revenue from form-originated leads
- AND the data MUST be filterable by date range

#### Scenario: Field completion analysis
- GIVEN a form with 8 fields where 3 are optional
- WHEN the admin views field analytics
- THEN the system MUST show the completion rate per optional field
- AND fields with low completion rates MUST be highlighted for potential removal

---

## Dependencies
- Pipelinq contact, lead, and request entities (OpenRegister schemas)
- Pipeline configuration (for default lead placement)
- Nextcloud notification system (OCP\Notification\IManager)
- Nextcloud Files (OCP\Files\IRootFolder) for file uploads
- CORS configuration for cross-origin form submissions
- Nextcloud public route system (`#[PublicPage]` attribute on controllers)
- SystemTag lead sources (for auto-setting source to "website")
- CRM workflow automation spec (for triggering automations on form-created entities)

---

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. There are no form builder components, public form endpoints, or embed code generation.

**Not yet implemented:**
- **Form builder UI:** No form builder component or form entity/schema.
- **Form field types:** No configurable field type system.
- **Field-to-entity mapping:** No mechanism to map form fields to contact/lead/request properties.
- **Form submission creates CRM entities:** No public submission endpoint. No contact deduplication by email.
- **Form embedding:** No iframe or JavaScript embed snippet generation. No public controller routes.
- **Custom styling:** No form style configuration.
- **Spam protection:** No honeypot field, rate limiting, or CAPTCHA integration.
- **Form management:** No form list, submission history, or CSV export.
- **Public API routes:** No public (non-authenticated) controller endpoints for form rendering or submission.
- **CORS configuration:** No cross-origin headers for external form submissions.
- **Form analytics:** No submission statistics or conversion tracking.
- **File upload for public forms:** No public file upload endpoint.
- **NL Design System styling:** No design token integration for government forms.

**Partial implementations:**
- `#[PublicPage]` attribute is available in Nextcloud for creating public routes (used by other apps).
- OpenRegister API provides the entity creation backend (contacts, leads, requests).
- SystemTag-based lead sources include "website" (initialized in `InitializeSettings.php`).
- NotificationService exists and can be reused for form submission notifications.
- Nextcloud Files integration (IRootFolder) is available for file uploads.

### Standards & References
- **CORS (Cross-Origin Resource Sharing):** Required for forms embedded on external websites.
- **hCaptcha / Cloudflare Turnstile:** CAPTCHA providers mentioned in the spec. hCaptcha preferred for GDPR compliance.
- **HTTPS:** All public form endpoints must be served over HTTPS.
- **GDPR/AVG:** Public forms collecting personal data must comply with privacy regulations. IP address hashing, data retention policies, and right-to-deletion support are required.
- **Nextcloud public routes:** Nextcloud supports public (non-authenticated) controller routes via `#[PublicPage]` attribute.
- **NL Design System:** CSS custom properties (design tokens) for government website integration.
- **WCAG AA:** All forms must be accessible, including CAPTCHA alternatives.
- **Shadow DOM:** Used for style isolation in JavaScript embed mode.
- **Krayin web-forms:** Competitive reference for web-to-lead form patterns (attribute selection, color customization, embed snippet).
- **EspoCRM Lead Capture:** Competitive reference for webhook-based lead creation from external forms.

### Specificity Assessment
- The spec now defines 12 requirements with 3-5 scenarios each, covering the form builder, field mapping, entity creation, embedding, styling, spam protection, success/error handling, form management, submission history, public API routes, data storage, and analytics.
- **Implementable incrementally:** MVP covers the form builder, field mapping, entity creation, embedding (iframe + JS), spam protection (honeypot + rate limiting), success/error handling, form management, public routes, and data storage. V1 adds custom styling (NL Design System), submission history/export, field conditional visibility, and analytics. Enterprise features are not defined (this is a complete feature at V1).
- **Resolved:** Forms are stored as OpenRegister objects with a form schema.
- **Resolved:** Public URL structure is `/apps/pipelinq/public/forms/{slug}`.
- **Resolved:** File uploads use Nextcloud Files with service account ownership.
- **Resolved:** CORS is configurable per form with default allow-all.
- **Resolved:** AVG compliance addressed via IP hashing, data retention, and deletion support.
- **Design decision:** Forms support single-page layout only (no multi-step wizards in MVP/V1). Multi-step forms may be added as Enterprise feature.
- **Design decision:** Duplicate contact handling uses email as primary dedup key, with phone as secondary soft match (logged but not blocked).
