# Namespace the last two colliding slugs

## Why

`expense` and `mergeOperation` are the last two of the fleet's cross-app slug
collisions. Both were held back for a reading rather than renamed on a rule,
because both share more fields than the ones already settled.

## expense

humaniq's is an **employee reimbursement claim**: `employeeId`, `distanceKm`,
`travelType`, `receiptFile`, `reimbursedAt`, `managerUserId`. This app's is a
**billable client cost**: `client`, `billable`, `apSyncStatus`, `apSyncedAt`.

They share eight fields, which is more than any pair renamed so far, but every
one of them is a generic expense attribute: amount, currency, category, status,
title, description and the approval stamps. There is **no receipt number and no
expense number** — nothing that says these two rows are the same expense.

humaniq keeps the bare slug; this becomes `billableExpense`.

## mergeOperation

The same merge **mechanics** as openregister's — `preMergeSnapshot`,
`reversible`, `reversedAt`, `reversedBy`, `mergedAt` — applied to a different
subject.

openregister merges objects and records `mergedFromUuids` / `mergedIntoUuid`.
This app merges MDM master entities and records `mergedFromMasterIds` /
`mergedIntoMasterId`, plus `attributeResolutionLog` and
`downstreamSyncStatus`.

Different id spaces, so the shared fields describe a shared *pattern* rather
than a shared record. openregister owns the platform one; this becomes
`masterMergeOperation`.

## Decoy

`ApSyncNotifier` passes `objectType: 'expense'` to Nextcloud's notification API.
That is routing, not a schema lookup, and it stays.

`ExpenseApprovalListener` does move: it compares against
`SchemaMapService::resolveEntityType()`, whose value follows the slug.
