# client-management: delta for organisation-enrichment

## ADDED Requirements

### Requirement: Selected organisations can be filled in from the KvK

A user with the CRM capability MUST be able to select organisations and get, per organisation the KvK knows, a proposal for each empty field the KvK can fill (KvK number, legal form, place). A different value MUST be proposed unticked and flagged. Only accepted proposals MUST be written. People MUST NOT be looked up.

#### Scenario: an empty KvK number

- GIVEN Bakkerij De Molen has no KvK number
- WHEN Pieter selects it and chooses "Ontbrekende gegevens aanvullen"
- THEN the KvK number 00001008 is proposed and ticked
- AND "5 velden invullen" writes only the ticked fields

#### Scenario: an organisation the KvK does not know

- WHEN the KvK has no company with that exact name
- THEN the dialog names it as not found and proposes nothing for it
