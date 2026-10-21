# kennisbank: one route to xWiki

## ADDED Requirements

### Requirement: The knowledge base reaches the app only through OpenRegister's xwiki integration

Every knowledge-base surface in pipelinq SHALL read xWiki through OpenRegister's `xwiki` integration: search through `kb-search`, and the pages linked to a record through the integration's tab in the record's sidebar. pipelinq SHALL NOT route its own `/api/xwiki/*` endpoints.

@e2e tests/e2e/spec-coverage/kennisbank.spec.ts asserts the search answers from OpenRegister's endpoint; tests/e2e/xwiki-integration.spec.ts asserts the client's Knowledge base tab renders.

#### Scenario: The client's Knowledge base tab
- **GIVEN** the xwiki integration is registered
- **WHEN** a user opens a client and its Knowledge base tab
- **THEN** the tab shows the xwiki integration's pages for that client
