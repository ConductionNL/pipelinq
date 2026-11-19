# Design: clients-reversible-merge

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212)

- **OpenRegister's merge.** `appinfo/routes.php:654-657`: "MDM reversible merge
  surface (ADR-045 follow-on #B), preview / execute / reverse":
  `merge#preview`, `merge#execute` (`/api/objects/merge/preview`, `/execute`) and
  `merge#reverse` (`/api/objects/merge/{id}/reverse`). A schema takes part by
  declaring `x-openregister-merge`, validated by
  `lib/Service/Merge/MergeAnnotationValidator.php`: `entityType`, `statusField`,
  `survivorStatus`, `mergedStatus`, `reversalWindowDays` (positive integer) and
  an optional `sourceLink`. `ObjectsMergedEvent` fires on execute and on
  reversal (`isReversal()`).
- **How related rows move.** `lib/Listener/PartyMergeListener.php` in
  OpenRegister: "`mdm-merge` already has the preview, the atomic execution, the
  reversal window and the merge register, and it is entity-type-agnostic by
  requirement ... Every carried-over row records the operation that moved it,
  so the reversal is a read of the rows themselves".
- **pipelinq today.** Only `masterEntity` declares the dialect
  (`register.d/90-master-data-management.json:105`, `reversalWindowDays: 30`).
  `client.accountStatus` is `active | inactive | blocked`
  (`register.d/15-unify-client-contact.json`). `lib/Listener/ObjectsMergedSyncListener.php`
  (:99) already subscribes to `ObjectsMergedEvent` to sync downstream systems
  for master entities.
- **References to a client.** Seventeen properties point at a client:
  `billableExpense.client`, `billingTimeEntry.client`, `competitor.clientId`,
  `contact.client`, `crmTask.clientId`, `deliveryProgramme.client`,
  `enquiry.client`, `journeyRun.clientId`, `lead.client`, `posTransaction.client`,
  `salesContract.clientRef`, `satisfactionSurveyInvitation.clientRef`,
  `socialAccount.clientId`, `socialConnection.clientId`, `surveyResponse.clientRef`,
  `ticket.client`, `zgwEndpoint.clientId` (listed from every register file).

## Decisions

### D1. Declare the dialect on client and contact

Both schemas get `x-openregister-merge` with `entityType` `client` or `contact`,
`statusField: accountStatus` (client) or a new `recordStatus` (contact),
`survivorStatus: active`, `mergedStatus: merged`, and
`reversalWindowDays: 30`. `accountStatus` gains the value `merged`. No
`sourceLink`: the references are many, so D2 moves them.

### D2. One listener moves pipelinq references and records them

`ClientMergeReferenceListener` handles `ObjectsMergedEvent` for the client and
contact schemas. On execute it rewrites each reference property listed in
Context from a merged-away id to the survivor id, and stamps each moved row with
`mergeMovedBy: <mergeOperationId>` and the property name. On reversal it reads
the rows carrying that operation id and puts the old value back, then clears
the stamp. The property list lives in one constant with a unit test that fails
when a register file adds a client reference the list does not hold.

### D3. The Nextcloud contact follows

The merged-away record's Nextcloud contact is kept, not deleted, and marked in
its note; on reversal it is linked again. `ContactVcardService` is not asked to
delete anything during the window.

### D4. Screens

- ClientDetail and ContactDetail: Merge into another record opens a dialog that
  picks the survivor, calls `merge#preview` and shows which values win, then
  `merge#execute` on confirm.
- The survivor shows a Merges section (merge operations where it survived) with
  Undo while `reversalWindowDays` has not passed, calling `merge#reverse`.
- A merged-away record opened by link shows "merged into" with a link.

## Risks

- A reference property added later without updating D2's list would be left
  behind. The guard test in D2 is what prevents that.
- A row edited after the merge (for example a lead moved to another client by
  hand) is not moved back by the reversal: the stamp check skips rows whose
  value no longer equals the survivor id, and the reversal result lists them.
