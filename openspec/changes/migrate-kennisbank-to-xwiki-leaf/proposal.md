# Proposal: migrate-kennisbank-to-xwiki-leaf

## Why

Pipelinq ships a bespoke in-app wiki — the kennisbank: `src/views/kennisbank/`
and `src/components/kennisbank/` (article detail, category tree/manager,
feedback, list items), a `kennisbank.js` store, a Markdown editor, and the
`kennisartikel` / `kenniscategorie` / `kennisfeedback` schemas. Knowledge
management is not a CRM core competency, and OpenRegister now exposes wiki
content as a leaf: the **xwiki** leaf (`integration-xwiki`), with the
**collectives** leaf (`integration-collectives`) as the native-NC alternative.

Per hydra ADR-022, an app must consume the OR abstraction rather than maintain a
parallel knowledge system. The xwiki leaf ships `XwikiProvider` + a tab (link
pages by URL/path, display with breadcrumb + last-modified) + a widget (4
surfaces; detail-page shows a text preview of page content) + reference-property
chip. It is routed externally via OpenConnector.

This change **supersedes the older bespoke `xwiki-integration` change** (which
proposed a hand-rolled `XWikiController` proxy + `XWikiWidget`/`XWikiSidebarTab`
components + app-local xWiki settings). That approach predates the leaf and is
itself an ADR-022 anti-pattern (an app-local proxy mirroring an OR integration).
The leaf is the correct, fleet-shared mechanism.

## Decision: xwiki leaf (not collectives) — justified in design.md

xWiki is chosen over collectives because Conduction's established knowledge
platform is xWiki (the bespoke change already targeted xWiki on port 8088), and
the leaf relationship doc states xWiki covers the external-knowledge-platform
case while collectives covers native-NC-only. Full justification + the
collectives fallback are in design.md.

## What Changes

### Replace the in-app wiki with the xwiki leaf

1. **Remove the bespoke kennisbank** — `src/views/kennisbank/`,
   `src/components/kennisbank/`, `src/store/modules/kennisbank.js`, the Markdown
   editor, and the kennisbank routes/controllers.
2. **Retire the `kennisartikel` / `kenniscategorie` / `kennisfeedback` schemas**
   — articles live in xWiki. (Existing-content migration is a documented
   follow-up, not in scope here.)
3. **Supersede the older `xwiki-integration` change** — no hand-rolled proxy,
   widget, or sidebar tab; the leaf provides all of them.
4. **Add `xwiki` to `linkedTypes`** on the CRM schemas that should reference
   knowledge pages (`client`, `lead`, `request`).
5. **Place the leaf via the manifest (ADR-024).** The xwiki leaf tab mounts in
   the relevant detail sidebars (link page by URL/path, breadcrumb,
   last-modified); the widget shows a page preview on detail pages and optionally
   the dashboard.
6. **Declare the `openconnector` dependency** in `src/manifest.json`
   `dependencies[]` (the leaf is routed externally via OpenConnector with an
   `xwiki` source), and import the OpenConnector xWiki source template.

## Out of Scope

- Page editing — goes to xWiki.
- XWiki macro rendering beyond basic text preview.
- Migration of existing `kennisartikel` content into xWiki — documented
  follow-up.

## Impact

- **Removed**: `src/views/kennisbank/`, `src/components/kennisbank/`,
  `kennisbank.js` store, kennisbank routes/controllers, the bespoke Markdown
  editor; and the superseded `xwiki-integration` proxy/components.
- **Modified schemas**: `client`, `lead`, `request` gain `xwiki` in
  `linkedTypes`; `kennisartikel`/`kenniscategorie`/`kennisfeedback` retired.
- **Modified files**: `src/manifest.json` (tab/widget placement + `openconnector`
  dependency), `lib/Settings/pipelinq_register.json`.
- **Dependency**: OpenRegister `integration-xwiki` leaf shipped; `openconnector`
  installed with an `xwiki` source configured.
- **Risk**: Medium — knowledge content moves to xWiki; existing articles need a
  follow-up migration; OpenConnector source must be configured for the tab/widget
  to populate.
