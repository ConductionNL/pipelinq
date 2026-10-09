# mail-block-editor Specification (delta)

## Purpose

A marketer builds an email template from blocks instead of writing HTML, sees
the mail as it will arrive, and saves a template that passes the compliance
check. From pipelinq matrix row `mkt-block-editor`.

## ADDED Requirements

### Requirement: A marketer builds an email template from blocks (REQ-MBE-001)

The email template form SHALL offer a Blocks mode with heading, text, image,
button, divider, spacer, articles and footer blocks. A marketer SHALL add a
block by dragging it from the palette or with a button, and SHALL reorder
blocks by dragging or with Move up and Move down buttons. New email templates
SHALL open in Blocks mode.

#### Scenario: A marketer builds a newsletter without HTML

- GIVEN a marketer on New template with channel email
- WHEN they add a heading "Autumn news", a text block, a button "Read more" to https://www.example.nl and the articles block
- AND press Create template
- THEN the template is saved
- AND reopening it shows the same four blocks above the footer

#### Scenario: A marketer reorders blocks with the keyboard

- GIVEN a template in Blocks mode with a heading above a text block
- WHEN the marketer focuses the text block and activates Move up
- THEN the text block is above the heading
- AND focus stays on the text block

### Requirement: The preview shows what will be sent (REQ-MBE-002)

The editor SHALL show a preview rendered by the server from the current
blocks, at desktop and at phone width, with the picked articles filled in.

#### Scenario: The preview follows an edit

- GIVEN a template in Blocks mode with a button labelled "Register"
- WHEN the marketer changes the label to "Sign up"
- THEN the preview shows a button labelled "Sign up"

### Requirement: Saved blocks become mail-safe HTML (REQ-MBE-003)

On save, the system SHALL render the blocks to HTML and plain text on the
server and store them as the template's `bodyHtml` and `bodyText`. The
renderer SHALL escape text values, SHALL allow only `http`, `https` and
`mailto` links, and SHALL mail only `http` or `https` images. The footer block
SHALL carry `{{unsubscribe_link}}` and `{{physical_address}}` and SHALL NOT be
removable, so a template saved in Blocks mode passes the compliance check.

#### Scenario: A script in a heading is not sent as code

@e2e exclude server-side rendering rule, covered by MailBlockRendererTest::testAScriptInAHeadingIsText and TemplateControllerTest

- GIVEN a heading block with the text `<script>alert(1)</script>`
- WHEN the template is saved
- THEN the stored `bodyHtml` shows that text as text and contains no script element

#### Scenario: A blocks template passes compliance

@e2e exclude save-path contract, covered by TemplateControllerTest::testABlocksTemplateIsRenderedOnSaveAndPassesCompliance against the real ComplianceService

- GIVEN a new template in Blocks mode with only a heading and the footer
- WHEN the marketer saves it
- THEN the save succeeds
- AND the stored `bodyHtml` contains the unsubscribe link token

### Requirement: HTML templates keep working (REQ-MBE-004)

A template without blocks SHALL open in HTML mode as today. Switching a
template from Blocks to HTML SHALL ask for confirmation and SHALL keep the
rendered HTML. The Reply-to address on the form SHALL be saved.

#### Scenario: An existing HTML template opens as before

- GIVEN a template saved before this change with an HTML body
- WHEN a marketer opens it
- THEN the form shows the HTML body in the text area

#### Scenario: Reply-to is kept

- GIVEN a marketer who enters reply@example.nl as Reply-to email
- WHEN they save and reopen the template
- THEN Reply-to email reads reply@example.nl
