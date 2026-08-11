## ADDED Requirements

### Requirement: App-owned vocabulary SHALL be renamed to English

pipelinq's own domain vocabulary SHALL use English identifiers, adopting the fleet's
ratified words where one applies rather than inventing an app-local alternative.

#### Scenario: The customer word follows the fleet list

- **WHEN** a property or schema names a customer
- **THEN** it SHALL use `customer`
- **AND** it SHALL match the word the other app carrying the same key uses

#### Scenario: The loyalty domain is renamed app-locally

- **WHEN** the loyalty programme schemas are renamed
- **THEN** the change SHALL require no cross-app coordination
- **AND** validity boundaries within it SHALL adopt the fleet's validity pair

### Requirement: Adapter values SHALL be preserved when adapter properties are renamed

A property whose value belongs to an external contract SHALL be renamed while its value
is left byte-identical. The property name is pipelinq's; the value is not.

#### Scenario: A VNG endpoint property is renamed but its URL is not

- **WHEN** a property holding a VNG Klantinteracties endpoint URL is renamed to English
- **THEN** the URL value SHALL be unchanged
- **AND** path segments within it SHALL NOT be translated

#### Scenario: An external product name is preserved in an identifier

- **WHEN** a schema or class names the MijnOverheid Berichtenbox
- **THEN** that product name SHALL be preserved
- **AND** only the surrounding Dutch words SHALL be renamed

#### Scenario: A cached wire field is classified before renaming

- **WHEN** a schema caches a response from an external registry
- **THEN** each property SHALL be classified as stored-as-received or computed by pipelinq
- **AND** only the computed ones SHALL be renamed

### Requirement: Renaming a privacy control SHALL NOT weaken it

Renaming a property that governs lawful access to personal data SHALL preserve its
constraints, its required-ness and its presence in the audit record.

#### Scenario: The purpose-limitation ground survives its rename intact

- **WHEN** the purpose-limitation property authorising a national-identity-number lookup
  is renamed
- **THEN** its required-ness SHALL be unchanged
- **AND** it SHALL still appear in the audit record
- **AND** no change SHALL widen who can read it

#### Scenario: The control is verified rather than assumed

- **WHEN** the rename lands
- **THEN** the property's constraints and audit coverage SHALL be asserted before and after
- **AND** a matching property name SHALL NOT be accepted as evidence the control is intact

### Requirement: Cross-app foreign keys SHALL be held for the coordinated window

`zaakId` and `zaaktype` SHALL NOT be renamed in this change. They are foreign keys into
procest's domain, and pipelinq is one of four apps holding them.

#### Scenario: The key is held

- **WHEN** the app-local rename lands
- **THEN** `zaakId` and `zaaktype` SHALL be unchanged
- **AND** the dependency on procest SHALL be recorded

#### Scenario: All four holders move together

- **WHEN** procest renames its case family
- **THEN** pipelinq, openconnector and docudesk SHALL rename in the same window
- **AND** no holder SHALL move alone
