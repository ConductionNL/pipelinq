# customer-portal Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `portal-login`, `portal-mfa`, `portal-file-request`, `portal-reply`, `portal-documents`, `portal-invoices`, `portal-delegation`, `portal-export`, `portal-close-account`, `portal-sign`.

## ADDED Requirements

### Requirement: A client logs in to the portal with an account of their own

The portal SHALL authenticate a client with a portal account that is separate from Nextcloud users, and SHALL show a logged-in client only the records of their own contact or organisation.

#### Scenario: A client sees only their own records

- GIVEN a portal account linked to one contact
- WHEN the client logs in with the account's password
- THEN the portal lists the invoices, contracts and requests of that contact only

#### Scenario: A portal session opens nothing in Nextcloud

- GIVEN a valid portal session
- WHEN it is used on a Nextcloud route outside the portal API
- THEN Nextcloud rejects the request as unauthenticated

### Requirement: The portal asks for a second factor when the tenant requires it

When a tenant requires a second factor, the portal SHALL make a client enrol a TOTP app and SHALL require a valid code before the login completes.

#### Scenario: A client without a second factor logs in

- GIVEN a tenant that requires a second factor
- AND a portal account without one
- WHEN the client enters the right password
- THEN the portal shows the enrolment code for an authenticator app
- AND the login completes only after a valid code is entered

### Requirement: A client files a request in the portal and follows it

The portal SHALL let a client submit a request with a subject, description, attachments and a category open to clients, and SHALL show the client its status and the notes meant for them.

#### Scenario: A client submits a request

- GIVEN a logged-in client
- WHEN they submit a request in a category open to clients
- THEN a request exists with the client as reporter and the portal as its channel
- AND the portal shows the request with its status

#### Scenario: Internal notes stay internal

- GIVEN a request with two notes for the client and one internal note
- WHEN the client opens the request in the portal
- THEN only the two notes for the client are shown

### Requirement: A client answers a question on their request in the portal

When a request waits for the client, the portal SHALL show a reply field, and a reply SHALL be stored as a note on the request that the client can see.

#### Scenario: Answering a question

- GIVEN a request with the status awaiting customer
- WHEN the client opens it in the portal, writes a reply and sends it
- THEN the reply is stored as a note on the request
- AND the request leaves the awaiting customer status

### Requirement: A client downloads their own documents through a short-lived link

The portal SHALL let a client download their own documents through a signed link that expires after five minutes, and SHALL log each download.

#### Scenario: Downloading an invoice

- GIVEN a client with an invoice document
- WHEN they download it from the portal
- THEN the file comes from a signed link that holds no storage path
- AND the download is logged with the client and the document

#### Scenario: An expired link

- GIVEN a download link issued six minutes ago
- WHEN it is opened
- THEN the portal answers that the link has expired and asks the client to download again

### Requirement: A client sees their own invoices, contracts, orders and quotes

The portal SHALL list a client's own invoices, contracts, orders and quotes, newest first, for each of these record types the tenant has switched on.

#### Scenario: Listing invoices

- GIVEN a client with three invoices
- WHEN they open Invoices in the portal
- THEN the three invoices are listed with number, date, amount and status, newest first

#### Scenario: A record type that is switched off

- GIVEN a tenant that has not switched on orders
- WHEN a client asks the portal for orders
- THEN the portal answers that the feature is not available
- AND its menu shows no Orders item

### Requirement: A business client gives another portal account access to part of their records

The portal SHALL let the owner of an organisation account grant another portal account access to named parts of the organisation's records, and SHALL show that account only what was granted.

#### Scenario: A bookkeeper sees the invoices

- GIVEN the owner of an organisation account grants a bookkeeper's portal account access to invoices
- WHEN the bookkeeper opens Invoices
- THEN they see the organisation's invoices, marked as shared with them
- AND they do not see its contracts

### Requirement: A client downloads everything the portal holds about them

The portal SHALL let a client request an export of their data, build it in the background, and send the client a download link by email within 30 days.

#### Scenario: Requesting an export

- GIVEN a logged-in client
- WHEN they request a data export
- THEN a background job collects their contact record, activity log and documents into one download
- AND the client receives a link to it by email

### Requirement: A client closes their own portal account

The portal SHALL let a client close their account after confirming by email, SHALL end all its sessions, and SHALL refuse later logins, while the records kept by law stay.

#### Scenario: Closing an account

- GIVEN a logged-in client
- WHEN they ask to close their account and confirm through the email link
- THEN the account is closed and all its sessions end
- AND a later login with that account is refused

### Requirement: A client signs a document from a link

The portal SHALL let a client open a document from a link sent to them and sign it, and SHALL store who signed it and when with the document.

#### Scenario: Signing a quote

- GIVEN a client who received a signing link for a quote
- WHEN they open it and confirm their signature
- THEN the quote is marked signed with the signer and the time
- AND the signed copy is stored with the record
