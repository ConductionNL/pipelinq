# Design: migrate-kennisbank-to-xwiki-leaf

status: pr-created

## Architecture

The bespoke kennisbank is replaced by the OpenRegister **xwiki leaf**
(`integration-xwiki`), routed externally via OpenConnector to an xWiki instance.

```
xWiki instance        owns pages, versioning, permissions, editing
        │  (OpenConnector `xwiki` source: Basic/OAuth auth)
[ openconnector ]
        │
[ xwiki leaf ]        XwikiProvider (storage='external')
   tab / widget / reference chip
        │  external link (object ↔ xWiki page URL/path)
        ▼
client / lead / request   tab: linked pages (title + breadcrumb + last-modified)
                          widget: page text preview
```

The leaf provides:
- `XwikiProvider` (`storage='external'`, references an `xwiki` OpenConnector
  source; `isEnabled()` mirrors `IAppManager::isInstalled('openconnector')`).
- A tab — link a page by URL or wiki path, display with breadcrumb +
  last-modified.
- A widget — 4 surfaces; detail-page shows a text preview of the page content.
- A reference-property `referenceType: 'xwiki'` rendering a page chip.

## Decision: xwiki vs collectives

The leaf catalogue offers two knowledge leaves:

| Leaf | Backend | Use case |
|---|---|---|
| `integration-xwiki` | external xWiki via OpenConnector | external structured-knowledge platform |
| `integration-collectives` | NC-native Collectives | internal-only NC knowledge |

**Chosen: `integration-xwiki`.** Rationale:
1. Conduction's established knowledge platform is xWiki — the docker env runs it
   on port 8088 and the prior bespoke `xwiki-integration` change already targeted
   it. Migrating to collectives would be a second platform switch on top of the
   leaf migration.
2. The leaf docs state explicitly: "Collectives covers the native-NC knowledge
   case. XWiki covers the external-knowledge-platform case." Pipelinq's
   kennisbank is shared, structured, cross-team knowledge that suits the external
   xWiki platform.
3. xWiki provides versioning, permissions, and structured spaces the bespoke
   kennisbank lacked.

**Collectives fallback:** if a tenant has no xWiki and wants NC-native-only
knowledge, the `integration-collectives` leaf is the drop-in alternative —
same tab/widget/reference-property contract, different backend. The migration
keeps the choice at the leaf level so a tenant can swap without app changes.

## Supersedes the bespoke xwiki-integration change

The older `xwiki-integration` change proposed a hand-rolled `XWikiController`
proxy, `XWikiWidget` / `XWikiSidebarTab` Vue components, and app-local xWiki
settings. That is an ADR-022 anti-pattern (an app-local proxy mirroring an OR
integration). This change supersedes it: the leaf owns the proxy (via
OpenConnector), the tab, the widget, and the settings.

## What Pipelinq owns after migration

1. `linkedTypes: ["xwiki", ...]` on `client`, `lead`, `request`.
2. Manifest placement (ADR-024): xwiki leaf tab in detail sidebars; xwiki widget
   on detail pages (+ optional dashboard).
3. `openconnector` in manifest `dependencies[]`; import the OpenConnector xWiki
   source template (`docs/Integrations/xwiki-openconnector-source.yaml`).

## Removed

| Bespoke artefact | Replaced by |
|---|---|
| `src/views/kennisbank/` + `src/components/kennisbank/` | xwiki leaf tab/widget |
| `src/store/modules/kennisbank.js` + Markdown editor | xWiki editing |
| `kennisartikel` / `kenniscategorie` / `kennisfeedback` schemas | xWiki pages |
| kennisbank routes/controllers | leaf (via OpenConnector) |
| superseded `xwiki-integration` proxy/widget/sidebar | xwiki leaf |

## Existing-content migration (follow-up, NOT in this change)

Existing `kennisartikel` content does not move automatically. A one-time
export → import-as-xWiki-pages → relink pass is required. Scoped as a separate
follow-up to keep this migration bounded (ADR-032). The maintainer SHOULD open a
tracking issue at apply time per the team's "file issues for deferred work"
convention.

**Follow-up tracking item (ADR-032):**
- Export all `kennisartikel` / `kenniscategorie` / `kennisfeedback` objects from
  OpenRegister via the Objects API export endpoint.
- Import content as xWiki pages in the `Kennisbank` space (preserving category
  hierarchy as sub-spaces).
- Re-link any objects that reference `kennisartikel` UUIDs to the new xWiki
  page URLs via the xwiki leaf link mechanism.
- Open a GitHub issue on ConductionNL/pipelinq tagged `follow-up:content-migration`
  to track this work.

status: follow-up — not in this change

## Risks

- Medium. Knowledge content moves to xWiki; users author in xWiki, not in
  Pipelinq.
- The tab/widget only populate once the OpenConnector `xwiki` source is
  configured; the leaf degrades gracefully (reconnect banner) when it is not.
- Existing kennisbank articles are stranded until the follow-up migration runs.
