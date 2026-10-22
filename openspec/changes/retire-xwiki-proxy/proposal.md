---
kind: code
---

## Why

pipelinq read xWiki twice: through OpenRegister's `xwiki` integration (the KCC workplace's `kb-search`), and through its own `/api/xwiki/*` proxy with a Pinia store and four components (a sidebar tab with an inline article reader, a widget, an article list and a viewer). Ruben decided (PL-ABSTRACT Q2, 21 Oct 2026) to use OpenRegister's integration everywhere. The cost is the inline reader in the sidebar: results open in xWiki.

## What changes

- The client page's Knowledge base sidebar tab holds the `xwiki` integration's tab (`{ "type": "integration", "integrationId": "xwiki" }`).
- `XWikiController`, `XWikiService`, the four `/api/xwiki/*` routes, `store/modules/xwiki.js`, `XWikiSidebarTab`, `XWikiWidget`, `XWikiArticleList`, `XWikiArticleViewer` and their tests are deleted.
- The e2e specs read OpenRegister's `/api/integrations/xwiki/search` instead of the proxy.

## Impact

- Needs nextcloud-vue `feature/pl-abstract-pl1-sidebar-widgets` (a sidebar tab widget of type `integration`).
- Lands after pipelinq `feature/pl-abstract-pl4-b`, which turns the dashboard's `XWikiDashboardWidget` (the last user of `XWikiWidget`) into `kb-search`.
- The `xwiki_cache_ttl` setting only tuned the proxy; its field on the admin page (PL5's Settings.vue) can go.
