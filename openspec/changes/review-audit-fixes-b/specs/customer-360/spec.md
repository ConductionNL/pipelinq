# customer-360 Specification (delta)

## ADDED Requirements

### Requirement: The customer 360 summary follows the client's read rights (REQ-RAF-030)

`GET /api/customer-360/summary` SHALL answer anyone who may read the client in
OpenRegister with that client's summary. The client read SHALL run as the caller,
pass the register and schema in their own parameters, and keep RBAC and
multitenancy on. A caller OpenRegister refuses SHALL get 403, a client that does
not exist SHALL give 404, and any other read failure SHALL give 500, never the
summary.

#### Scenario: A user who can read the client opens its page

- GIVEN a client the user may read
- WHEN the user opens the client page
- THEN the Open matters, SLA and Last activity widgets show the summary, not a 404

#### Scenario: A user who may not read the client asks

- GIVEN a client whose schema does not let the user read it
- WHEN that user asks for the client's summary
- THEN the answer is 403 and the summary is not computed
