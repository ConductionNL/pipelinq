# marketing-origin-on-client Specification (delta)

## Purpose

The campaign, source, medium and landing page of web form submissions reach the
contact and client record, from landing pages and from the website enquiry
form alike. From pipelinq matrix row `mkt-form-campaign-tags`.

## ADDED Requirements

### Requirement: Contacts and clients show how they found you (REQ-MOC-001)

A contact and a client SHALL carry a first touch and a last touch holding the
campaign, source, medium and landing page of a web form submission and its date.
The first touch SHALL never be overwritten; the last touch SHALL follow every
new submission. ContactDetail and ClientDetail SHALL show both.

#### Scenario: A marketer sees where a new client came from

- GIVEN a visitor at Gemeente Zeist submits the landing page form of campaign webinar-ai-voor-gemeenten from a LinkedIn link
- WHEN a marketer opens ClientDetail of Gemeente Zeist
- THEN How they found us shows first touch campaign webinar-ai-voor-gemeenten, source linkedin, with the landing page and the date

#### Scenario: A later submission changes only the last touch

- GIVEN a contact whose first touch is campaign voorjaar
- WHEN the contact submits another form from campaign najaar
- THEN the first touch still says voorjaar and the last touch says najaar

### Requirement: Every submission is listed on the record (REQ-MOC-002)

ContactDetail and ClientDetail SHALL list every touchpoint of the contact, or of
the client's contacts, newest first, with date, campaign, channel, source,
medium and landing page.

#### Scenario: Three submissions over a year

- GIVEN a contact with three web form submissions this year
- WHEN an account manager opens ContactDetail
- THEN the Touchpoints list shows three rows with their campaigns and landing pages

### Requirement: The website enquiry form keeps campaign values (REQ-MOC-003)

A website form submits into its destination object (decision 179). When the
form's destination is a lead, the lead SHALL carry the visit's campaign values in
`lastTouch` and the page in `pageId`, and the lead intake SHALL write the touches
of the lead's contact and client from them. Campaign values SHALL be trimmed and
cut at 100 characters and SHALL NOT set any server-owned field.

#### Scenario: An enquiry from a campaign link

- GIVEN a known contact opens the website with utm_campaign=voorjaar and utm_source=nieuwsbrief
- WHEN they send a website form whose destination is a lead
- THEN their last touch shows campaign voorjaar, source nieuwsbrief and the page
- AND the lead's status is the one the server sets, whatever the form sent

### Requirement: The touches are written as pipelinq's system account (REQ-MOC-004)

Every read and write that records a submission's touchpoint and touches SHALL run
as pipelinq's system account with OpenRegister's access checks on, whoever is
signed in, and SHALL NOT use `_rbac: false`. The same holds for the contact,
the client and the lead both intakes (the web form lead and the landing page
submission) find or create. The register's authorization block
decides what that account may touch. A failure SHALL be logged and SHALL NOT fail
the visitor's submission.

#### Scenario: A signed-in resident sends a campaign form

- GIVEN a resident is signed in to the site and sends a landing page form
- WHEN the touches are written
- THEN OpenRegister sees the system account, with access checks on
- AND the resident's own session is unchanged afterwards

#### Scenario: An anonymous visitor's form creates the contact, client and lead

- GIVEN nobody is signed in and a visitor sends a landing page form with an organisation
- WHEN the contact, the client and the lead are created
- THEN each write reaches OpenRegister as the system account, with access checks on
- AND the visitor is anonymous again afterwards
