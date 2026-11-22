# request-management: delta for request-dialog-client-lookups

## ADDED Requirements

### Requirement: The new request dialog finds the client or looks them up

The new request dialog MUST let the handler search the clients by name and pick one from a list that names each client's kind and address and marks an existing client as known. Under the list it MUST offer a BRP lookup, a KVK lookup and New client. A BRP lookup MUST only be offered to a user the BRP settings allow, and the server MUST check that permission before it asks the BRP. A lookup that matches selects the existing client with that identity, or creates a client from the found name and address; the BSN MUST NOT be stored on the ticket.

#### Scenario: a known client

@e2e exclude Spec only so far; the build of this change adds the Playwright test.

- GIVEN the client "Sanne de Vries" exists with one open ticket
- WHEN the handler types "de Vries" under Klant
- THEN the list shows "Sanne de Vries" with "Inwoner", her address and "1 open ticket", marked "Bekend"
- AND picking her puts her on the request

#### Scenario: a resident not yet a client

@e2e exclude Spec only so far; the build of this change adds the Playwright test.

- GIVEN the handler may look up in the BRP
- WHEN the handler chooses "Opzoeken in de BRP", gives the BSN, a reason and the purpose binding
- AND the BRP finds the person
- THEN a resident client with the found name and address is created and put on the request
- AND the lookup is recorded in the BRP audit trail

#### Scenario: a company not yet a client

@e2e exclude Spec only so far; the build of this change adds the Playwright test.

- WHEN the handler chooses "Opzoeken in de KVK" and searches "Bakkerij De Graaf"
- AND picks the company from the results
- THEN an organisation client with its KVK number, legal form and place is created and put on the request

#### Scenario: a handler without BRP access

@e2e exclude Spec only so far; the build of this change adds the Playwright test.

- GIVEN the handler is not in the groups the BRP settings allow
- WHEN the handler opens the new request dialog
- THEN "Opzoeken in de BRP" is not offered
- AND a direct call to the lookup endpoint is refused
