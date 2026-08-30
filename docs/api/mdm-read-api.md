<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# MDM read access (downstream apps)

> **The app-side MDM read-API was removed** (`retire-mdm-sync-queue`, ADR-022 /
> ADR-045 #D). Pipelinq no longer exposes `/api/mdm/master` or
> `/api/mdm/master/{id}`. Downstream apps read master-entity golden records
> **directly from OpenRegister's object API**, which is the system of record.

## Reading a master entity from OpenRegister

Master entities are stored as OpenRegister objects in the Pipelinq register under
the `masterEntity` schema. Query them through OpenRegister's object surface, which
is RBAC- and multitenancy-scoped:

```
GET /apps/openregister/api/objects/{register}/masterEntity?<filters>
```

The returned object carries the golden record plus the OR-materialised
`qualityScore` (previously projected app-side as `dataQualityScore`). Natural-key
lookups (KvK, VAT, email, phone, SKU) are expressed as object filters against the
golden-record fields — see the OpenRegister object-API documentation for filter
syntax, pagination, and authentication (session or bearer token).

## Why the change

The app-side wrapper duplicated OpenRegister object reads (redundant-controller
rule) and offered no capability OpenRegister's object API does not. Consuming OR
directly removes a hop, keeps one authorization model, and lets downstream apps
use the same object-API client they already use for every other OR-backed read.

## See also

- [Master Data Management — administrator guide](../admin/master-data-management.md)
- OpenRegister object-API documentation (register/schema object reads + filters)
