# Tasks: migrate-kennisbank-to-xwiki-leaf

## 0. Leaf check

- [x] 0.1 Confirm the OpenRegister `integration-xwiki` leaf is shipped (XwikiProvider + tab + widget + reference chip; storage='external' via OpenConnector) and note its key `xwiki`.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-xwiki/`
    - THEN document the leaf key `xwiki`, required NC app `openconnector`, and the `xwiki` OpenConnector source template.

## 1. Remove bespoke kennisbank + schemas + superseded change

- [x] 1.1 Remove the kennisbank views/components/store/editor and routes/controllers.
  - **spec_ref**: `specs/kennisbank/spec.md#Requirement: Knowledge is provided by the xwiki leaf, not an in-app wiki`
  - **files**: `pipelinq/src/views/kennisbank/`, `pipelinq/src/components/kennisbank/`, `pipelinq/src/store/modules/kennisbank.js`, kennisbank controller/routes
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no in-app wiki, Markdown editor, or kennisbank routes remain.

- [x] 1.2 Retire `kennisartikel`, `kenniscategorie`, `kennisfeedback` schemas in `lib/Settings/pipelinq_register.json`.
  - **spec_ref**: `specs/kennisbank/spec.md#Scenario: Bespoke kennisbank and schemas are removed`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN those three schemas are removed; existing objects left in place pending the follow-up migration.

- [x] 1.3 Supersede the bespoke `xwiki-integration` change (do not build proxy/widget/sidebar).
  - **spec_ref**: `specs/kennisbank/spec.md#Scenario: The bespoke xwiki-integration change is superseded`
  - **acceptance_criteria**:
    - GIVEN the leaf provides proxy/tab/widget/settings
    - THEN no hand-rolled `XWikiController`, `XWikiWidget`, or `XWikiSidebarTab` is built; the older change is archived as superseded (maintainer follow-up).

## 2. Schema glue

- [x] 2.1 Add `xwiki` to `linkedTypes` on `client`, `lead`, `request`.
  - **spec_ref**: `specs/kennisbank/spec.md#Requirement: CRM objects expose the xwiki leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `client`, `lead`, `request` list `xwiki` in `linkedTypes`.

## 3. Manifest placement (ADR-024)

- [x] 3.1 Place the xwiki leaf tab in detail sidebars and the widget; declare `openconnector` dependency; import the xWiki source template.
  - **spec_ref**: `specs/kennisbank/spec.md#Requirement: xwiki leaf is placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN client/lead/request detail pages include the xwiki tab; detail pages (optionally dashboard) include the widget; `dependencies[]` includes `openconnector`.

## 4. Follow-up flag

- [x] 4.1 Record the existing-content migration as a separate follow-up.
  - **spec_ref**: `specs/kennisbank/spec.md#Requirement: Existing content migration is a documented follow-up`
  - **acceptance_criteria**:
    - GIVEN existing `kennisartikel`/`kenniscategorie`/`kennisfeedback` objects
    - THEN a follow-up tracking item is recorded (export → import-as-xWiki-pages → relink); not built here.

## 5. Verification

- [x] 5.1 `npm run build` and `npm run check:manifest` pass.
- [x] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
- [x] 5.3 Browser check: with `openconnector` + `xwiki` source + leaf installed, open a client detail; xwiki tab links a page (breadcrumb + last-modified); widget shows preview.
  - **status**: deferred — leaf-environment precondition not met in the local Nextcloud dev container (the OpenRegister `integration-xwiki` leaf is upstream-merged in `openregister`/`nextcloud-vue` but not yet registered in this NC instance, and no `xwiki` OpenConnector source is configured). Structural verification stands in: `npm run check:manifest` parses the manifest with `CnXwikiTab` (label "Knowledge") on `client` / `lead` / `request` detail sidebars, `CnXwikiWidget` previews on the same detail pages, and `openconnector` in `dependencies[]`; `npm run build` is green; and `client` / `lead` / `request` `linkedTypes` advertise `xwiki`. Live browser walk-through is reserved for the dedicated `integration-xwiki` rollout that lands the leaf + source in the dev environment.
- [x] 5.4 Confirm the kennisbank views/components/store/schemas and the bespoke xwiki proxy are gone.
