# Open tickets of a party, read by master entity

## Why

dossiq's organisation list and organisation page draw a party's open
pipelinq tickets ("Open tickets" on DqOrganisaties, the tickets card on
DqOrganisatie). dossiq's party is its own record (a `kvkCompany` or
`brpPerson`), not a pipelinq client, so dossiq cannot name the client a ticket
points at. Both records can carry the master entity they describe:
`masterEntityRef`, the designed cross-app join. This adds a leaf that answers
on that key.

## What changes

- `OCA\Pipelinq\Integration\TicketLeafProvider::openByMasterEntity(refs)`
  returns, for each master entity, the open requests and complaints of the
  clients that carry it. Interactions are contact moments and are not counted.
  Closed, completed, converted, rejected and resolved tickets are not open.
- The leaf reads through OpenRegister's object service as the calling user. It
  answers 503 when the surface is not configured, never an empty list, and 400
  for more than 200 references (ADR-058).
- The Zuiddrecht example set gives the cast's resident and business clients
  their `masterEntityRef`, and gives Slagerij Van der Berg one open ticket and
  CloudGemeente B.V. two.
