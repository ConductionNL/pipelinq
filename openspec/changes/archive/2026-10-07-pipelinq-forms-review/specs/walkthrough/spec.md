# Walkthrough: the getting-started tour

## ADDED Requirements

### Requirement: REQ-WT-001 — The tour starts with a client

The getting-started tour SHALL ask the user to create a client first, then a contact on that client, then a product, then a lead linked to the client and contact. The create client dialog SHALL signal `cn-walkthrough:object-created` with register `pipelinq` and schema `client`, so the create client step advances.

#### Scenario: Create client advances the tour

- GIVEN the tour is on the create client step
- WHEN the user saves a client in the create client dialog
- THEN the tour SHALL move on to the Contacts step

@e2e exclude a tour run needs a fresh walkthrough state per test; checked live on the review instance.
