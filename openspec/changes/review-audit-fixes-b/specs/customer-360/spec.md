# customer-360 Specification (delta)

## ADDED Requirements

### Requirement: The customer 360 summary answers privileged users (REQ-RAF-030)

`GET /api/customer-360/summary` SHALL answer a member of a privileged group
(admin, sales or the configured CRM group) with the summary of any client that
exists and that OpenRegister lets that user see. The client read SHALL pass the
register and schema in their own parameters, with RBAC and multitenancy on. A
caller outside the privileged groups SHALL get 403 and no read.

#### Scenario: An admin opens a client page

- GIVEN a client that exists
- WHEN an admin opens the client page
- THEN the Open matters, SLA and Last activity widgets show the summary, not a 404

#### Scenario: A user outside the privileged groups asks

- GIVEN a user who is not in admin, sales or the CRM group
- WHEN that user asks for a client's summary
- THEN the answer is 403 and the client is not read
