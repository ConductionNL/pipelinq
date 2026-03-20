# Public Intake Forms - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Form Builder

The system MUST provide a no-code form builder for creating public intake forms.

#### Scenario: Create a basic contact form
- GIVEN a Pipelinq admin
- WHEN they navigate to Settings > Formulieren > Nieuw
- THEN a form builder MUST display with form name, description, add field button, and preview

#### Scenario: Supported form field types
- GIVEN the form builder
- THEN the following field types MUST be supported: Text, Textarea, Email, Phone, Select, Radio, Checkbox, Date, File upload, Hidden

### Requirement: Public Form Rendering

The system MUST render forms publicly without requiring authentication.

#### Scenario: Submit public form creates a lead
- GIVEN a published form embedded on a website
- WHEN a visitor fills in and submits the form
- THEN a new lead MUST be created in the configured pipeline
- AND a contact MUST be created or matched by email address

### Requirement: Embed Code Generation

The system MUST generate embed codes for external website integration.

#### Scenario: Generate iframe embed code
- GIVEN a published form
- WHEN the admin copies the embed code
- THEN an iframe snippet MUST be provided with the form's public URL
