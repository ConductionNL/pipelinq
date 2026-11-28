# client-contact-capture Specification (delta)

## Purpose

A user turns pasted text or a photo of a business card into a prefilled
contact form and saves it after checking. From pipelinq matrix rows
`clients-smart-paste` and `clients-business-card-scan`.

## ADDED Requirements

### Requirement: Pasted text becomes proposed contact fields (REQ-CCC-001)

The Clients and Contacts lists SHALL offer Paste or scan. Pasted text SHALL be
split into proposed fields for name, organisation, job title, email, phone,
website, address and KvK number. Lines the system cannot place SHALL be shown
so the user can assign them. The system SHALL NOT save anything until the user
saves the create form.

#### Scenario: A sales rep pastes an email signature

- GIVEN a sales rep on the Contacts list with a signature holding a name, a job title, an email and a mobile number
- WHEN they choose Paste or scan, paste the signature and press Use these fields
- THEN the create form opens with the email, the phone in international format, the name and the job title filled in
- AND the contact list is unchanged until they press Save

#### Scenario: Without hermiq, unplaced lines are handed to the user

- GIVEN an instance without hermiq
- WHEN a sales rep pastes a signature
- THEN email and phone are filled in
- AND the name and job title lines are listed as unassigned with a field picker

### Requirement: A business card photo is read into the same fields (REQ-CCC-002)

The Paste or scan dialog SHALL accept a photo from the camera or a file when a
Nextcloud optical character recognition provider is available, SHALL pass the
recognised text through the same field proposal, and SHALL NOT keep the image.
Without a provider it SHALL say that photos cannot be read and keep the paste
option.

#### Scenario: A sales rep scans a card at a fair

- GIVEN a sales rep on their phone on the Clients list, with a recognition provider installed
- WHEN they choose Paste or scan, take a photo of a business card and press Use these fields
- THEN the create form opens with the card's email, phone and organisation filled in

#### Scenario: No recognition provider

- GIVEN an instance with no provider for `core:image2text:ocr`
- WHEN a user opens Paste or scan
- THEN the dialog says photos cannot be read on this server
- AND the paste area is available
