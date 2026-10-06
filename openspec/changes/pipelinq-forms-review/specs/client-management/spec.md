# Client management: form review fixes

Delta over `specs/client-management`.

## ADDED Requirements

### Requirement: REQ-CM-FORMS-001 — Forms ask in a deliberate order

The product, client, contact, lead, leadProduct and crmTask schemas SHALL give every form field an `order`, so forms do not fall back to alphabetical order. A product form SHALL start with name and description; a client form with name, type and industry; a contact form with name and client; a lead form with title, client and contact; a task form with subject and type.

#### Scenario: The product form starts with name and description

- GIVEN the product schema
- WHEN the create product form renders
- THEN the first two fields SHALL be name and description

@e2e exclude the order is a static schema property read by nextcloud-vue's fieldsFromSchema (overrides.order, then prop.order).

### Requirement: REQ-CM-FORMS-002 — Master data fields explain themselves

`isMasterRecord` and `masterEntityRef` on client, contact and product SHALL carry an `x-help` text that says what a master record is.

#### Scenario: The (i) next to Is master record

- GIVEN the client form
- WHEN the user opens the (i) next to "Is master record"
- THEN it SHALL explain that a master record is the one trusted version others follow

@e2e exclude rendered by nextcloud-vue once it reads `x-help`.

### Requirement: REQ-CM-FORMS-003 — Pickers instead of typed ids

Every property that holds a Nextcloud user id SHALL declare `format: nc-user`, and every property that holds a group id `format: nc-group`. `correspondenceLanguage` SHALL declare `format: language` and `x-default: current-language`; `timezone` SHALL declare `format: timezone`. The references contact.client, lead.client, lead.contact and leadProduct.product SHALL declare `x-allow-create: true`. `client.parentOrganisation` SHALL be a `$ref` to client. The hand-written create client form SHALL offer industry as a multi-select, the account owner as a user search defaulting to the current user, the correspondence language defaulting to the user's language, and the timezone as a list.

#### Scenario: Assign a task to a colleague

- GIVEN the create task form
- WHEN the user opens Assignee
- THEN a searchable list of Nextcloud users SHALL open instead of a text box

@e2e exclude rendered by nextcloud-vue once it maps `nc-user`; the schema half is a static property.

#### Scenario: A new client is written to in my language

- GIVEN a user whose language is Dutch on an instance that writes Dutch
- WHEN the user opens Create client
- THEN Correspondence language SHALL be preselected as Dutch

@e2e exclude asserted by tests/vitest/clientFormFields.spec.js (defaultLanguage).

### Requirement: REQ-CM-FORMS-004 — One language field and industry as a list

The forms SHALL show `correspondenceLanguage` and hide `language`. The repair step `NormalisePartyFormFields` SHALL copy a party's `language` into an empty `correspondenceLanguage` and SHALL never overwrite a set one. `client.industry` SHALL be a list of strings; the same repair step SHALL wrap a stored string into a one-item list. A segment rule `industry equals X` SHALL match a client whose industry list contains X.

#### Scenario: A stated language survives

- GIVEN a client with `language: nl` and no correspondence language
- WHEN the repair step runs
- THEN its correspondence language SHALL be `nl`

@e2e exclude asserted by tests/Unit/Repair/NormalisePartyFormFieldsTest.php.

#### Scenario: An industry string becomes a list

- GIVEN a client with `industry: "Retail"`
- WHEN the repair step runs
- THEN its industry SHALL be `["Retail"]`

@e2e exclude asserted by tests/Unit/Repair/NormalisePartyFormFieldsTest.php.
