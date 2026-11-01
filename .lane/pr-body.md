## What

A host app can read a party's open tickets by the master entity it shares with a pipelinq client.

- `TicketLeafProvider::openByMasterEntity(refs)` returns, per `masterEntityRef`, the open requests and complaints of the clients that carry it.
  - Interactions are contact moments, so they are not counted.
  - Closed, completed, converted, rejected and resolved tickets are not open.
  - It answers 503 when the ticket surface is not configured, and 400 for more than 200 references.
- In the Zuiddrecht example set, the cast's clients get the `masterEntityRef` that dossiq's matching parties carry. Slagerij Van der Berg gets one open ticket and CloudGemeente B.V. two.

dossiq reads this through `PipelinqGateway`. The dossiq side is `r23/organisation-open-tickets`: the "Open tickets" column on the organisation list and a Tickets card on the organisation and contact pages, both hidden without pipelinq.

## Access

Both reads go through `ObjectService::findAll` with its defaults, `_rbac` and `_multitenancy` both true.

- `GetObject::findAll` passes both flags into the search query.
- The RBAC handler then reads the user from `IUserSession`, so the reads run as the calling user.
- The ticket and client schemas grant read only to sales, dossiq-medewerkers, zuiddrecht-kcc and pipelinq-system. A signed-in user outside those groups gets no tickets and no counts, whatever master entity they name.
- `testBothReadsKeepRbacAndMultitenancyOn` pins both reads to RBAC and multitenancy on. Passing `false` makes the test fail; I checked that.

## Verified

- `TicketLeafProviderTest`: 6 tests, 20 assertions.
- `composer check:strict`: all checks passed.
- The example-set validator reports CLEAN.
- Against :8080 (read-only):
  - OpenRegister's `in` and `notIn` operators work on the ticket schema.
  - No client on :8080 carries a `masterEntityRef` yet, so the example set must be re-installed. This is listed in the DQ-BOARDSCFG data gaps.

AI-assisted (Claude Code).
