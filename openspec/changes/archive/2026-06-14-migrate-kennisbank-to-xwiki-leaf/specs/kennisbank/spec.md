# Spec delta — migrate-kennisbank-to-xwiki-leaf

## ADDED Requirements

### Requirement: Knowledge is provided by the xwiki leaf, not an in-app wiki

Pipelinq SHALL NOT ship an in-app wiki; knowledge content, authoring, and
versioning SHALL be provided by xWiki via the OpenRegister xwiki leaf
(`integration-xwiki`), routed externally through OpenConnector (hydra ADR-022).

#### Scenario: Bespoke kennisbank and schemas are removed

- **GIVEN** the migrate-kennisbank-to-xwiki-leaf change is applied
- **THEN** `src/views/kennisbank/`, `src/components/kennisbank/`,
  `src/store/modules/kennisbank.js`, the Markdown editor, and the kennisbank
  routes/controllers SHALL be removed
- **AND** the `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas
  SHALL be retired
- **AND** page authoring SHALL live in xWiki.

#### Scenario: The bespoke xwiki-integration change is superseded

- **GIVEN** the older `xwiki-integration` change (hand-rolled proxy + widget +
  sidebar + app-local settings)
- **WHEN** this migration is applied
- **THEN** the hand-rolled `XWikiController` proxy, `XWikiWidget`,
  `XWikiSidebarTab`, and app-local xWiki settings SHALL NOT be built
- **AND** the leaf SHALL own the proxy (via OpenConnector), tab, widget, and
  settings.

### Requirement: CRM objects expose the xwiki leaf

The `client`, `lead`, and `request` schemas SHALL declare `xwiki` in
`linkedTypes` so the leaf's tab and widget appear on those objects.

#### Scenario: xWiki tab and widget appear on CRM objects

- **GIVEN** `openconnector` is installed with an `xwiki` source configured and
  the xwiki leaf is registered
- **WHEN** a user opens a `client`, `lead`, or `request` detail page
- **THEN** the leaf's tab SHALL allow linking an xWiki page by URL or wiki path
  and display it with breadcrumb + last-modified
- **AND** the leaf's widget SHALL show a text preview of the linked page.

### Requirement: xwiki leaf is placed via the app manifest

The xwiki leaf's tab and widget SHALL be surfaced through `src/manifest.json`
(ADR-024), and `openconnector` SHALL be declared as a dependency.

#### Scenario: Manifest places tab/widget and declares dependency

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** the client/lead/request detail pages' `sidebar` config SHALL include
  the xwiki leaf tab
- **AND** detail pages (and optionally the dashboard) MAY include the xwiki
  widget
- **AND** `dependencies[]` SHALL include `openconnector`.

### Requirement: A collectives fallback is preserved at the leaf level

The `integration-collectives` leaf SHALL be usable as a drop-in alternative for
a tenant that has no xWiki and wants NC-native-only knowledge, without app code
changes.

#### Scenario: Tenant without xWiki uses collectives

- **GIVEN** a tenant with no xWiki instance
- **WHEN** they prefer NC-native knowledge
- **THEN** the collectives leaf MAY be substituted (same tab/widget/reference
  contract, different backend)
- **AND** no pipelinq-side wiki code SHALL be required to support either choice.

### Requirement: Existing content migration is a documented follow-up

Migration of existing `kennisartikel` content into xWiki SHALL NOT be performed
by this change and SHALL be documented as a separate follow-up (ADR-032 bounded
scope).

#### Scenario: Follow-up is recorded, not silently dropped

- **GIVEN** existing `kennisartikel` / `kenniscategorie` / `kennisfeedback`
  objects
- **WHEN** this migration is applied
- **THEN** those objects SHALL be left in place and a follow-up tracking item
  SHALL be recorded for a one-time export → import-as-xWiki-pages → relink pass.
