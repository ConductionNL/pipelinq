# marketing-mail-templates Specification (delta)

## Purpose

A marketing mail is written once as a template and reused for many mailings, personalised per recipient. Covers row mkt-template. Boards PqSjablonen and PqNieuwsbriefWizard.

## ADDED Requirements

### Requirement: A template holds a reusable mail (REQ-MMT-001)

The system SHALL store a mail template as a `campaignTemplate` object with a name, a channel (email or SMS), for email a subject, an HTML body and a text body, for SMS a text body, a sender identity and the list of variables the body uses. The Templates page SHALL list the templates with name, channel and last change, and SHALL let a marketer create, edit, duplicate and delete one. A template that is used by a scheduled mailing SHALL NOT be deletable.

#### Scenario: A marketer saves a template
- GIVEN a marketer on the Templates page
- WHEN they create "Monthly newsletter" for email with a subject, body and sender
- THEN the template is listed with channel Email

#### Scenario: Duplicating a template
- GIVEN the template "Monthly newsletter"
- WHEN the marketer duplicates it
- THEN a new template "Monthly newsletter (copy)" holds the same subject, bodies and sender

#### Scenario: A template in use
- GIVEN a mailing scheduled for tomorrow uses "Monthly newsletter"
- WHEN the marketer tries to delete the template
- THEN the deletion is refused with the name of the mailing that uses it

### Requirement: A mailing reuses a template, personalised per recipient (REQ-MMT-002)

A mailing SHALL reference its template by `templateId`. On send, the system SHALL render the template for each recipient, replacing `{{variable}}` with the recipient's value HTML-escaped and `{{{variable}}}` unescaped, and `{{preferredName}}` with the preferred name or else the full name. A variable without a value SHALL render empty, and the mailing's preview SHALL list the recipients for whom a used variable is empty before sending.

#### Scenario: Personalised greeting
- GIVEN a template body "Dear {{preferredName}}," and a recipient with preferred name Anna
- WHEN the mailing is sent
- THEN Anna's mail reads "Dear Anna,"

#### Scenario: A value with markup
- GIVEN a recipient whose organisation name is `<b>Acme</b>`
- WHEN a template with `{{organisation}}` is rendered
- THEN the mail shows the text `<b>Acme</b>` and not bold text

#### Scenario: Missing values are shown before sending
- GIVEN a template using `{{city}}` and 12 recipients without a city
- WHEN the marketer opens the preview
- THEN the preview says 12 recipients have no value for city

### Requirement: A mail from the wizard can be saved as a template (REQ-MMT-003)

The newsletter wizard SHALL offer "Save as template" on its content step, which stores the current subject, bodies and sender as a new template under a name the marketer gives, and SHALL offer "Start from a template" on its first step.

#### Scenario: Saving the wizard's content
- GIVEN a marketer has written the content in the newsletter wizard
- WHEN they choose Save as template and name it "Event invitation"
- THEN "Event invitation" appears on the Templates page with that content

### Requirement: A sent mailing keeps the content it was sent with (REQ-MMT-004)

When a mailing is sent, the system SHALL keep the subject and bodies it sent with the mailing, so that changing or deleting the template later SHALL NOT change what the mailing shows it sent.

#### Scenario: The template changes after a send
- GIVEN the mailing "October newsletter" was sent with template "Monthly newsletter"
- WHEN the marketer changes the template's subject
- THEN the October newsletter still shows the subject it was sent with
