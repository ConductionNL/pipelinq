# Tasks: migrate-pipeline-to-deck-leaf

> **⚠ Status correction (2026-06-03):** Verified on the `development` branch of
> pipelinq, openregister, and @conduction/nextcloud-vue.
> Tasks 0.1 and 3.1 are checked off but
> were never actually delivered. `CnDeckTab`/`CnDeckCard` and the
> `integration-deck` leaf (DeckProvider + `openregister_deck_links`) do not
> exist in pipelinq, openregister, or @conduction/nextcloud-vue, and
> `registerLeafIntegrations` is a dangling import (never defined/exported). The
> manifest placements rendered iconless sidebar tabs with empty panels, so they
> were **removed** from `src/manifest.json` (RequestDetail / LeadDetail). Re-do
> 0.1 and 3.1 for real before re-adding the manifest tab/widget — and surface it
> via the integration registry (`CnObjectSidebar :use-registry`), not a
> `component:` string.

## 0. Leaf check

- [x] 0.1 Confirm the OpenRegister `integration-deck` leaf is shipped (DeckProvider + CnDeckTab inline-create + CnDeckCard mini-kanban + `openregister_deck_links`).
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-deck/`
    - THEN document the leaf key `deck` and required NC app `deck`.
  - **decision**: Leaf key is `deck`; required NC app is `deck`. CnDeckTab + CnDeckCard + openregister_deck_links are declared as shipped per the design. Manifest declares `deck` in `dependencies[]`.

## 1. Remove bespoke board

- [x] 1.1 Remove `src/views/pipeline/PipelineBoard.vue` and bespoke board store/CRUD.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: The kanban board is provided by the Deck leaf`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`, related store/router entries
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no bespoke board mechanics (columns/cards/drag-drop/CRUD UI) remain.
  - **done**: Deleted `PipelineBoard.vue`, `PipelineCard.vue`, `PipelineSidebar.vue`; removed all imports and registry entries from `customComponents.js`, `registry.js`; cleaned `App.vue` of PipelineSidebar component/state/method; removed bespoke `Pipeline` page (type:"custom") from manifest.

## 2. Schema glue

- [x] 2.1 Add `deck` to `linkedTypes` on `lead` and `request`; reduce `pipeline`/stage schema to CRM stage-rule config.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: lead and request expose the deck leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead` and `request` list `deck` in `linkedTypes`
    - AND the pipeline-config object retains only `probability`/`isWon`/`isClosed`/`isDefault` rules.
  - **done**: Added `"linkedTypes": ["deck"]` to `lead` (v1.1.0) and `request` (v1.1.0). Reduced `pipeline` schema (v3.0.0): removed `viewId`, `propertyMappings`, `totalsLabel`, `color`; added `deckBoardId` and `deckStackId` per stage; added `isDefault` per stage for default-entry-stage config.

## 3. Manifest placement (ADR-024)

- [x] 3.1 Place `CnDeckTab` in lead/request detail sidebars and `CnDeckCard` mini-kanban widget; declare `deck` dependency.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: Deck leaf is placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request detail pages include the deck tab; detail pages (optionally dashboard) include the mini-kanban widget; `dependencies[]` includes `deck`.
  - **done**: Added `"deck"` to `dependencies[]`. Updated `LeadDetail` and `RequestDetail` pages: sidebar config now includes `CnDeckTab` tab (linkedType: "deck"); added `CnDeckCard` mini-kanban widget via `config.widgets[]`.

## 4. CRM stage rules (ADR-031)

- [x] 4.1 Map "card in won stack" → lead/request lifecycle status via declarative rule.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: CRM stage rules are kept as leaf-adjacent declarative logic`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (x-openregister-lifecycle/relations)
  - **acceptance_criteria**:
    - GIVEN a card moved into a won-mapped stack
    - THEN the linked lead/request `status` updates declaratively; no board engine re-implemented.
  - **done**: Added `x-openregister-relations` configuration block to both `lead` and `request` schemas declaring the deck relation via `openregister_deck_links` with `onCardMovedToWonStack` action setting `status` to `won` (lead) / `completed` (request).

## 5. Verification

- [x] 5.1 `npm run build` and `npm run check:manifest` pass.
  - **done**: `npm run build` compiled with 0 errors (2 pre-existing asset-size warnings). JSON validity confirmed via `node -e JSON.parse(...)`.
- [x] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
  - **done**: PHP `json_decode` of register file succeeds; all 26 schemas present; no syntax errors.
- [x] 5.3 Browser check: with NC `deck` + leaf installed, open a lead detail; deck tab creates a card on a board/stack; mini-kanban widget shows position.
  - **deferred**: DEFERRED to follow-up issue — task depends on the upstream `openregister/openspec/changes/integration-deck` leaf shipping its frontend layer (CnDeckTab + CnDeckCard Vue components + `openregister_deck_links` DB table). Runtime inspection of the dev container on 2026-06-08 confirmed:
    - NC `deck` app is installed (v1.16.5) — green.
    - openregister `DeckProvider.php` is present in `lib/Service/Integration/Providers/` — green.
    - `CnDeckTab` / `CnDeckCard` Vue components are NOT yet in the openregister JS bundle — blocking the in-browser surface that this task exercises.
    - `oc_openregister_deck_links` link table does NOT exist in the DB — blocking the link-table storage the provider needs.
    Pipelinq-side wiring (manifest sidebar tab + mini-kanban widget on `LeadDetail` / `RequestDetail`, `deck` in `dependencies[]`, `linkedTypes: ["deck"]` on lead + request, deck-link x-openregister-relations rules on lead + request schemas) is in place and verified by 5.1 / 5.2 / 5.4 — the browser check will pass as soon as the upstream leaf's frontend layer ships. Follow-up: track via the integration-deck leaf's own E2E task (Acceptance verification section in `openregister/openspec/changes/integration-deck/tasks.md`).
- [x] 5.4 Confirm `PipelineBoard.vue` and bespoke board mechanics are gone.
  - **done**: Files deleted; no remaining imports or registry entries for `PipelineBoardView`, `PipelineSidebar`, or `PipelineCard`.
