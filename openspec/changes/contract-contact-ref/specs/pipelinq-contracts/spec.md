# pipelinq-contracts: delta for contract-contact-ref

## ADDED Requirements

### Requirement: A contract may name the contact person it was made with

A sales contract MAY carry `contactRef`, the uuid of a contact person. It MUST NOT be required. The contact page's Gerelateerd card MUST list the contracts whose `contactRef` is that contact.

#### Scenario: Joke Smit's contract

@e2e exclude Asserted in tests/Unit/Settings/SalesContractContactRefSchemaTest.php and tests/vitest/contactPersonPage.spec.js; the pixel check against board PqContactpersoon covers the card.

- GIVEN contract CON-2026-0031 with `contactRef` Joke Smit
- WHEN the user opens Joke Smit's contact page
- THEN Gerelateerd lists "Contract CON-2026-0031", linking to the contract

### Requirement: A contract without a renewal outcome stays valid

`renewalLeadOutcome` MUST accept null as well as "", won and lost. OpenRegister stores the empty default as null, and a later edit of that contract MUST validate.

#### Scenario: edit a contract that has no renewal outcome

@e2e exclude Asserted in tests/Unit/Settings/SalesContractContactRefSchemaTest.php::testTheRenewalLeadOutcomeAcceptsNull.

- GIVEN contract CON-2026-0031, stored with renewalLeadOutcome null
- WHEN it is changed (an edit, or the example set adding contactRef)
- THEN the change validates and is saved
