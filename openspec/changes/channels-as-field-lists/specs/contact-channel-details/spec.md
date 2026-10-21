# Contact channel details (delta)

## ADDED Requirements

### Requirement: Detail pages show channels as field lists

The client and contact detail pages SHALL show the emails, phone numbers and social profiles as three tabs of field lists, counted off the record, with add, edit and remove per entry, and the preferred channel, timezone and language in a separate card.

#### Scenario: an address links and offers Write email
- GIVEN a contact with a work email address marked primary
- WHEN the agent opens the Emails tab
- THEN the address is a `mailto:` link with the pills Work and Primary
- AND Write email opens the email composer for that address

#### Scenario: a wrong address is refused
- WHEN the agent adds `jan@example` as an email address
- THEN the form says "Invalid email format" and nothing is saved

#### Scenario: one primary entry
- GIVEN a primary work address
- WHEN the agent adds a private address marked primary
- THEN only the new address is primary

#### Scenario: the change reaches Nextcloud Contacts
- WHEN a save of the emails or phones went through
- THEN the write-back to Nextcloud Contacts is called
- AND when it fails, the save stands and the agent reads that Contacts still has the old details

### Requirement: The server keeps the single email and phone equal to the primary entry

Before a client or contact is stored, the server SHALL set `email` and `phone` to the value of the primary entry of `emails` and `phones`, else of the first entry, else empty; a record without the list keeps its single field.

#### Scenario: a primary entry is mirrored
- GIVEN emails `first@example.nl` and `main@example.nl` (primary)
- WHEN the contact is saved
- THEN `email` is `main@example.nl`
