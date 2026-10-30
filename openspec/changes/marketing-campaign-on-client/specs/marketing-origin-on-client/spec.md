# marketing-origin-on-client Specification (delta)

## Purpose

The campaign, source, medium and landing page of web form submissions reach the
contact and client record, from landing pages and from the website enquiry
form alike. From pipelinq matrix row `mkt-form-campaign-tags`.

## ADDED Requirements

### Requirement: Contacts and clients show how they found you (REQ-MOC-001)

A contact and a client SHALL carry a first touch and a last touch holding the
campaign, source, medium, landing page and form of a web form submission and its
date. The first touch SHALL never be overwritten; the last touch SHALL follow
every new submission. ClientDetail SHALL show the last touch in its Identity
card as board PqKlant draws it: Form (the form and its date), Landing page, and
Campaign (the campaign and its source). No board draws the touches on
ContactDetail, so ContactDetail keeps them without showing them (decision 130).

#### Scenario: A marketer sees where a new client came from

- GIVEN a visitor at Gemeente Zeist submits the landing page form of campaign webinar-ai-voor-gemeenten from a LinkedIn link
- WHEN a marketer opens ClientDetail of Gemeente Zeist
- THEN the Identity card shows the form and its date, the landing page, and campaign webinar-ai-voor-gemeenten with source linkedin

#### Scenario: A later submission changes only the last touch
<!-- @e2e exclude server-side touch writing, asserted in TouchpointServiceTest -->

- GIVEN a contact whose first touch is campaign voorjaar
- WHEN the contact submits another form from campaign najaar
- THEN the first touch still says voorjaar and the last touch says najaar

### Requirement: Every submission is listed on the record (REQ-MOC-002)

ContactDetail and ClientDetail SHALL list every touchpoint of the contact, or of
the client's contacts, newest first, with date, campaign, channel, source,
medium and landing page.

#### Scenario: Three submissions over a year
<!-- @e2e exclude not built: no board draws the Touchpoints list yet (decision 130) -->

- GIVEN a contact with three web form submissions this year
- WHEN an account manager opens ContactDetail
- THEN the Touchpoints list shows three rows with their campaigns and landing pages

### Requirement: A website form submission keeps its campaign values (REQ-MOC-003)

A website form submits into a lead (decision 179) carrying the page's campaign
values as the lead's last touch. When the lead is stored, the system SHALL
write the submission's touchpoint and SHALL give the lead's contact and client
their touches, trimmed and capped at 255 characters. The campaign values SHALL
NOT set any server-owned field.

#### Scenario: A form sent from a campaign link
<!-- @e2e exclude server-side intake, asserted in SubmittedLeadIntakeTest -->

- GIVEN a known contact opens the website with utm_campaign=voorjaar and utm_source=nieuwsbrief
- WHEN they send a form that submits into a lead
- THEN their last touch shows campaign voorjaar, source nieuwsbrief and the form page
- AND the lead's status is set by the server, whatever the form sent
