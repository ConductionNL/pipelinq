## Purpose

Lets a client or contact person carry more than one email address and phone number, a set of social network profiles, and a stated channel/timezone/language preference, so the marketing programme's segments, mailings and social outreach have real, typed contact points to target instead of a single unlabelled value.

## ADDED Requirements

### Requirement: Client and contact schemas carry typed channel arrays

The `client` and `contact` schemas SHALL declare `emails[]` (`kind`, `value`, `primary`, `verified`), `phones[]` (same shape), `socialProfiles[]` (`network`, `handle`, `url`, `verified`, `followedByUs`, `followsUs`), `preferredChannel`, `timezone` and `language`. Each array entry's `kind`/`network` SHALL be restricted to a fixed enum. These properties SHALL be additive: the existing single `email`/`phone` fields SHALL remain on both schemas, unchanged in shape.

#### Scenario: A client can carry multiple typed emails and phones

- **GIVEN** a client object
- **WHEN** it is saved with `emails: [{kind: "work", value: "info@acme.example", primary: true, verified: false}]` and `phones: [{kind: "mobile", value: "+31612345678", primary: true, verified: false}]`
- **THEN** the save SHALL succeed and both arrays SHALL be stored on the object

@e2e exclude schema validation is enforced by OpenRegister at save time, not independently by Pipelinq UI code; exercised indirectly by the detail-page e2e scenarios below and by the register fragment's own JSON-schema validity

### Requirement: Detail pages display channels as a linked list with kind chips

The Client and Contact detail pages SHALL display each entity's `emails[]` as a list of `mailto:` links, `phones[]` as a list of `tel:` links, and `socialProfiles[]` as a list of profile links, each row labelled with its `kind`/`network` and, when set, a primary and/or verified indicator. An entity with no channels of a given type SHALL show an empty-state message for that list rather than nothing.

#### Scenario: A client's emails render as clickable mailto links with a kind label

- **GIVEN** a client with `emails: [{kind: "work", value: "info@acme.example", primary: true, verified: false}]`
- **WHEN** the client's detail page is opened
- **THEN** the page SHALL show a link `mailto:info@acme.example` labelled with its kind

#### Scenario: No channels shows an empty state

- **GIVEN** a client with no `emails[]` entries
- **WHEN** the client's detail page is opened
- **THEN** the emails list SHALL show an empty-state message instead of an empty list

### Requirement: Channels are added, edited and removed through dedicated modals

The detail page SHALL offer an "Add" affordance for each of `emails[]`, `phones[]` and `socialProfiles[]` that opens a dedicated modal for that channel type. Saving the modal SHALL add or update the entry and persist the entity. Marking an entry primary SHALL clear any other entry's primary flag in the same array, and the legacy scalar `email`/`phone` field SHALL be recomputed from the array's primary entry (falling back to the first entry when none is marked primary) as part of that save.

#### Scenario: Adding an email opens a modal and persists on save

- **GIVEN** a client's detail page with no emails
- **WHEN** the user clicks "Add" on the Emails section, fills in an address and saves
- **THEN** the modal SHALL close and the new email SHALL appear in the list

#### Scenario: Marking a second email primary demotes the first

- **GIVEN** a client with one primary email
- **WHEN** the user adds a second email and marks it primary
- **THEN** the first email SHALL no longer show the primary indicator
