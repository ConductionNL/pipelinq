# Tasks — mdm-consume-or-surface (config head link)

Config-only. All edits land in `lib/Settings/register.d/90-master-data-management.json` and the OR
`trust-configuration` seed (see design → Deferred Questions for the OR-repo seed mechanism).

- [x] Add `x-openregister-survivorship` to the `masterEntity` schema: `sourceLinkField: sourceRecords`, `goldenRecordField: goldenRecord`, `provenanceField: attributeProvenance`, `trustTierField`, `tierOrder` (weakest-first incl. `discard`), `freshnessAnchorField`, `overridesField: attributeOverrides`.
- [x] Add `x-openregister-merge` to the `masterEntity` schema naming the survivor/merged status fields and `sourceLinkField`.
- [x] Rewrite `masterEntity.x-openregister-dedup` matchRules to nested `goldenRecord.*` paths (`goldenRecord.kvkNumber`, `goldenRecord.email`, `goldenRecord.name`) keeping the 0.7 threshold.
- [ ] Remove the flattened `matchName`, `matchEmail`, `matchKvkNumber`, `matchPhone` properties from `masterEntity`. *(STAGE 2 — deferred to the backend link `mdm-consume-or-surface-backend`, which also deletes the app-side maintenance that populates them; kept in place now so the still-live backend stays runtime-safe.)*
- [x] Add an `attributeOverrides` object property (with `title`) to `masterEntity` for OR's per-object override map.
- [ ] Remove the pipelinq-local `trustConfiguration` schema declaration and its entry in the `pipelinq` register `schemas[]` list. *(STAGE 2 — deferred to the backend link, alongside the `TrustConfigurationService` deletion; kept now so the running app still resolves the schema.)*
- [ ] Remove the three seeded `trustConfiguration` objects from the pipelinq register `objects[]`. *(STAGE 2 — deferred with the schema removal above.)*
- [ ] Seed the three trust rows one-to-one into OpenRegister's `trust-configuration` register (per the resolved seed mechanism). *(STAGE 2 — needs the OR-repo `trust-configuration` seed companion change.)*
- [x] Verify every retained/added `masterEntity` property has a `title` and no dangling schema references remain.
- [x] `openspec validate mdm-consume-or-surface --strict` passes; register JSON re-parses cleanly.

## Acceptance criteria

- `masterEntity` declares `x-openregister-survivorship` (with `overridesField`) and `x-openregister-merge`.
- `x-openregister-dedup` matchRules reference nested `goldenRecord.*` paths only; no `match*` projection fields remain.
- `masterEntity` declares an `attributeOverrides` property.
- The pipelinq register file no longer declares `trustConfiguration`; the three trust rows exist in OR's register.
- masterEntity seed golden records/provenance unchanged; no `match*` seed values.

## Quality checklist

- Register JSON is valid and re-parses after every edit (json-merge-revalidate).
- No backend or frontend code touched in this link (single-surface config).
- schema-property-titles gate: all properties carry a `title`.
- No dangling `schemas[]` reference to the removed `trustConfiguration` schema.
- Deferred Questions in design.md reflect the chain split and every genuine keep/drop/rewire ambiguity.
