# The sales contract is a facet, not a third contract

## Why

`contract` was claimed by three apps: shillinq, stackiq and this one. A schema
slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so whichever row the lookup reached first answered for all three.

All three carry `contractNumber`. That is not three contracts, it is one
contract seen from billing, from the catalogue and from sales.

The direction was already settled in the tree: this app's ticket supertype
carries a `contract` property described as "References a Shillinq Contract
object (ADR-066 cross-app reference); Shillinq owns contract lifecycle". The
sales-side schema simply never pointed at it.

## What changes

The slug becomes `salesContract` and the schema gains a `contract` uuid
pointing at shillinq's `Contract`. A plain uuid and not a `$ref`, because
shillinq's register is a different register and ADR-062 rule 7 gives a
cross-register target a plain string. Empty when shillinq is absent, in which
case the record stands alone on `contractNumber`.

The app-config key stays `contract_schema`, pinned through
`SettingsLoadService::SCHEMA_CONFIG_KEYS` the way `cashCount_schema` and
`klantLoyaltyAccount_schema` already are.

## Five decoys that deliberately did not move

`contract` is also a **GDPR Article 6 lawful basis**. A blanket rename would
have rewritten it in three register fragments and in
`ComplianceService::LAWFUL_BASIS_ALLOWED`, silently changing what consent
records claim their legal ground is.

Also untouched: a log-context key in `RenewalWindowJob`, the
`relatedEntityType` label in `RenewalEngineService`, a UI dropdown value in
`SendMessageModal`, and the ticket supertype's existing `contract` property,
which already points at shillinq and is the thing this change aligns with.
