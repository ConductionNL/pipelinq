---
kind: config
depends_on: []
---

## Why

ADR-045 decides that OpenRegister — not each leaf app — owns the generic MDM / data-governance
surface. pipelinq is the canonical app that violated that boundary before OR had the surface: it
ships a full Master Data Management module — a survivorship engine (`MasterEntityService`), a
duplicate detector, a data-quality scorer, a reversible merge service, a trust-configuration
service, an outbound sync-queue subsystem, and ~5 steward Vue views/components plus a merge wizard
and a conflict-resolution modal.

OR's MDM stack is now **complete and merged to OR development**: the quality API, the
survivorship engine + `trustConfiguration` register + `x-openregister-survivorship` annotation,
the steward Data-Quality / Duplicates / Master-entities / Queue-health views, nested-path dedup,
a reversible merge engine + `x-openregister-merge` + `ObjectsMergedEvent`, the merge wizard +
MergeOperations UI, and per-object conflict-resolution/override (`overridesField` +
`POST /api/objects/survivorship/{id}/override` + a conflict modal). This is the payoff step of
ADR-045 (#D): migrate pipelinq to **fully consume** OR's surface and remove the app-side MDM
engine + UI.

The migration is far too large for a single OpenSpec change and it mixes a config surface (schema
annotations, seed rows) with a code surface (delete/rewire backend, remove frontend). Per ADR-032
that must be a **chain**, and per the single-surface rule each link must be config-only or
code-only — never a mixed monolith. This change is the **head config link** of that chain: it
performs the schema migration on the `masterEntity` schema and migrates the `trustConfiguration`
rows into OR's register. The backend-deletion link and the frontend-removal link both depend on
this one and are proposed in `design.md` → Deferred Questions as `mdm-consume-or-surface-backend`
and `mdm-consume-or-surface-frontend`.

## What Changes

This head link (config, `mdm-consume-or-surface`) changes only declarative configuration in
`lib/Settings/register.d/90-master-data-management.json` and the seeded rows:

- **ADD `x-openregister-survivorship`** to the `masterEntity` schema (sourceLinkField=`sourceRecords`,
  goldenRecordField=`goldenRecord`, provenanceField=`attributeProvenance`, trustTierField, tierOrder,
  freshnessAnchorField, overridesField=`attributeOverrides`) so OR's `SurvivorshipRecomputeListener`
  materialises the golden record + provenance on every save — replacing `MasterEntityService`.
- **ADD `x-openregister-merge`** to the `masterEntity` schema so OR's merge engine can preview /
  execute / reverse merges and fire `ObjectsMergedEvent` — replacing `MergeService`.
- **Switch `x-openregister-dedup` matchRules to NESTED `goldenRecord.*` paths** (now supported by
  OR's nested-path dedup) and **drop the flattened `matchName` / `matchEmail` / `matchKvkNumber` /
  `matchPhone` projection fields** together with the app-side maintenance that populates them.
- **ADD an `attributeOverrides` property** to `masterEntity` (the per-object conflict-override map
  OR's override endpoint writes and the resolver reads).
- **Migrate the seeded `trustConfiguration` rows** out of the pipelinq register into OR's own
  `trust-configuration` register (OR now owns that schema/register); the pipelinq-local
  `trustConfiguration` schema declaration is removed from this register file.

No backend or frontend code is touched in this link. Deleting/thinning the MDM services, jobs and
controllers, rewiring the sync queue to `ObjectsMergedEvent`, and removing the MDM views / nav /
registry (deep-linking to OR's surface) are the two dependent code links.

## Impact

- **Affected capability spec (this repo):** `master-data-management` — MODIFIED. Requirements that
  described app-owned survivorship / dedup / merge / trust config now describe **consuming OR's
  materialised surface** driven by schema annotations; the app-side engine requirements are
  retired to the dependent code links.
- **Affected config:** `lib/Settings/register.d/90-master-data-management.json` (schema annotations,
  dropped `match*` fields, dropped local `trustConfiguration` schema, seed-row migration).
- **Consumes:** OR `mdm-survivorship`, `mdm-merge`, `mdm-quality-api`, `duplicate-detection`
  (nested paths), `mdm-conflict-resolution-ui`, and OR's `trust-configuration` register.
- **References:** ADR-045 (#D payoff), ADR-022 (apps consume OR abstractions), ADR-032 (spec
  sizing → chain), ADR-041 (cross-app propagation via events, not phantom RPC).
- **Downstream chain (dependent, proposed here):** `mdm-consume-or-surface-backend` (code),
  `mdm-consume-or-surface-frontend` (code).
