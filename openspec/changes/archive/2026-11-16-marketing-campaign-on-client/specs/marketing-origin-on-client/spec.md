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

The public enquiry endpoint SHALL accept campaign, source, medium, content and
term values and the page URL, SHALL trim and cap them, and SHALL NOT let them set
any server-owned field. An enquiry from a known contact SHALL update that
contact's touches.

#### Scenario: An enquiry from a campaign link

- GIVEN a known contact opens the website with utm_campaign=voorjaar and utm_source=nieuwsbrief
- WHEN they send the enquiry form
- THEN their last touch shows campaign voorjaar, source nieuwsbrief and the page URL
- AND the enquiry's status is new, whatever the form sent
