# Pipelinq Final Review -- 2026-03-21

## OpenSpec Structure
| Item | Count | Status |
|------|-------|--------|
| Active changes | 0 | OK -- no active changes in openspec/changes/ |
| Baseline specs | 30 | OK (activity-timeline, admin-settings, client-management, contact-relationship-mapping, contactmomenten-rapportage, contacts-sync, crm-workflow-automation, dashboard, email-calendar-sync, entity-notes, kcc-werkplek, kennisbank, klantbeeld-360, lead-management, lead-product-link, my-work, notifications-activity, omnichannel-registratie, openregister-integration, pipeline, pipeline-insights, product-catalog, product-catalog-quoting, product-service-catalog, prometheus-metrics, prospect-discovery, public-intake-forms, register-i18n, request-management, terugbel-taakbeheer) |
| Archived changes | 43 | OK -- 12 early archives (2026-02-25/26) + 1 mid-cycle (2026-03-15) + 30 final archives (2026-03-21) |
| Architecture docs | 1 | adr-001-international-first-dutch-mapping.md |
| config.yaml | Present | Well-structured with context, standards, and spec rules |

## Unit Tests
| Result | Count |
|--------|-------|
| Tests | 54 |
| Assertions | 195 |
| Status | **Pass** (100%) |
| Coverage | 0% lines (tests mock all dependencies; code coverage requires Nextcloud runtime) |
| Quality checks | **All passed** (composer check:strict -- PHPCS, PHPMD, Psalm, PHPStan) |

Tests run inside Docker container (`docker exec -w /var/www/html/custom_apps/pipelinq nextcloud php vendor/bin/phpunit`). Test files cover: ActivityService, MetricsFormatter, NotesService, NotificationService, PipelineStageData, ProspectScoringService, SchemaMapService (7 test classes, 54 tests).

## Browser Test Results
| Page | Status | Notes |
|------|--------|-------|
| Dashboard | OK | KPI cards (Open Leads, Open Requests, Pipeline Value, Overdue) render with "No items found". Sections: Requests by Status, My Work, Client Overview. Quick action buttons: New Lead, New Request, New Client. "Failed to fetch pipeline" with Retry button (expected -- OpenRegister schemas not configured). |
| Clients | OK | Cards/Table toggle, Add Item, Actions buttons. "No items found" empty state. Console: `Object type "client" is not registered` (OpenRegister not configured). |
| Contacts | OK | Same layout as Clients. Cards/Table toggle, Add Item, Actions. Console: `Object type "contact" is not registered`. |
| Leads | OK | Same layout pattern. Console: `Object type "lead" is not registered`. |
| Requests | OK | Same layout pattern. Console: `Object type "request" is not registered`. |
| Products | OK | Same layout pattern. Console: `Object type "product" is not registered`. |
| Pipeline | OK | Kanban/List view toggle, pipeline selector dropdown, pipeline settings button. Sidebar with Details/Stages tabs. Shows "No pipeline selected" with "New pipeline" button. Minor: NcSelect accessibility warning (missing inputLabel). |
| My Work | OK | Filter buttons (All, Leads, Requests), "Show completed" checkbox. "Failed to fetch lead" with Retry (expected -- no data). Left navigation loads after brief delay. |
| Admin Settings | OK | Comprehensive. Version Information (v0.1.15, "Up to date"), Register Configuration (fails to fetch registers from OpenRegister -- expected), Pipelines ("No pipelines configured" with "Create first pipeline"), Product Categories, Lead Sources ("+ Add Source"), Request Channels ("+ Add Channel"), Prospect Discovery (ICP configuration). Multiple NcSelect/NcInputField accessibility warnings. |
| Documentation link | BROKEN | Sidebar "Documentation" link points to `href="#"` -- does nothing. |

### Root Route Bug
Navigating to `http://localhost:8080/apps/pipelinq/` returns **404 Page not found**. The SPA catch-all route on line 53 of `appinfo/routes.php` uses `{path}` with `defaults => ['path' => '']`, but the Nextcloud router does not match the empty-path case. Workaround: navigating to any sub-path (e.g., `/apps/pipelinq/dashboard`) loads the app and redirects to `/apps/pipelinq/`. The duplicate route on line 7 (`url => '/'`) is shadowed by the catch-all and never registered.

### Comparison with Previous Review (2026-03-20)
The previous review identified 10 issues. Current status:

| Previous Issue | Status |
|---------------|--------|
| Object type registration errors on list pages | PERSISTS -- same errors on all entity list pages. Expected when OpenRegister schemas are not configured. |
| Admin Settings pipeline vs Pipeline page inconsistency | IMPROVED -- both now show "no pipelines" consistently (no stale data). |
| contacts-sync/write-back 404 | NOT TESTED -- no client data to trigger sync. |
| Unknown custom element `<CnDet...>` | NOT OBSERVED -- not seen in this session. |
| Client detail header overlap | NOT TESTED -- no data to view detail pages. |
| Documentation link broken | PERSISTS -- still `href="#"`. |
| NcSelect accessibility warnings | PERSISTS -- present on Pipeline and Admin Settings pages. |
| NcInputField label warnings | PERSISTS -- present on Admin Settings. |
| Vue development mode | PERSISTS -- console shows "You are running Vue in development mode". |

## Codebase Metrics
| Metric | Count |
|--------|-------|
| PHP files (lib/) | 60 |
| Vue components (src/) | 43 |
| Controllers | 10 |
| Services | 33 |
| Pinia store modules | 6 |
| Dashboard widgets | 4 (ClientSearch, DealsOverview, MyLeads, RecentActivities) |
| Translation keys (en/nl) | 393 each |
| Repair steps | 1 (InitializeSettings) |

## Documentation
| Feature Doc | Exists | Screenshot |
|-------------|--------|------------|
| activity-timeline.md | Yes | No |
| admin-settings.md | Yes | Yes (admin-settings.png) |
| client-management.md | Yes | Yes (client-management.png) |
| contact-relationship-mapping.md | Yes | Yes (contacts.png) |
| contactmomenten-rapportage.md | Yes | No |
| contacts-sync.md | Yes | No |
| crm-workflow-automation.md | Yes | No |
| dashboard.md | Yes | Yes (dashboard.png) |
| email-calendar-sync.md | Yes | No |
| entity-notes.md | Yes | No |
| kcc-werkplek.md | Yes | No |
| kennisbank.md | Yes | No |
| klantbeeld-360.md | Yes | No |
| lead-management.md | Yes | Yes (lead-management.png) |
| lead-product-link.md | Yes | No |
| my-work.md | Yes | Yes (my-work.png) |
| notifications-activity.md | Yes | No |
| omnichannel-registratie.md | Yes | No |
| openregister-integration.md | Yes | No |
| pipeline-insights.md | Yes | No |
| pipeline-kanban.md | Yes | Yes (pipeline.png) |
| pipeline.md | Yes | No |
| product-catalog-quoting.md | Yes | No |
| product-catalog.md | Yes | Yes (product-catalog.png) |
| product-service-catalog.md | Yes | No |
| prometheus-metrics.md | Yes | No |
| prospect-discovery.md | Yes | No |
| public-intake-forms.md | Yes | No |
| register-i18n.md | Yes | No |
| request-management.md | Yes | Yes (request-management.png) |
| terugbel-taakbeheer.md | Yes | No |

**Feature docs:** 34/34 (including README.md, administration.md, collaboration.md, pipeline-kanban.md as group docs)
**Screenshots:** 9/34 feature docs have corresponding screenshots
**Features README:** Well-structured -- links to 8 grouped feature docs with spec-to-feature mapping

## Issues Found

1. **ROOT ROUTE 404** -- Navigating to `/apps/pipelinq/` returns 404. The Nextcloud router does not match the empty-path default on the SPA catch-all route. Users must navigate via `/apps/pipelinq/dashboard` or click the app icon from the Nextcloud app menu (which uses a sub-path). This is a usability issue -- bookmarking or sharing the base URL will fail.

2. **Object type registration errors on all entity pages** -- All entity list pages (Clients, Contacts, Leads, Requests, Products) log `Error: Object type "X" is not registered in the store`. This is expected when OpenRegister schemas are not configured, but the error handling could be improved -- currently some pages show "No items found" (graceful) while the dashboard shows "Failed to fetch pipeline" with a Retry button (less graceful).

3. **Documentation link broken** -- The sidebar "Documentation" nav item links to `href="#"`. Should either point to a real documentation URL or be removed.

4. **NcSelect/NcInputField accessibility warnings** -- Multiple instances of missing `inputLabel` or `ariaLabelCombobox` props on NcSelect components (Pipeline page, Admin Settings) and missing labels on NcInputField components (Admin Settings). Affects WCAG compliance.

5. **Vue development mode** -- The JS bundle is built in development mode. Console shows "You are running Vue in development mode" on every page load.

6. **25 feature docs lack screenshots** -- Only 9 of 34 feature docs have corresponding screenshots. Many of these are for unimplemented/future features, but the implemented features (entity-notes, contacts-sync, notifications-activity) should have screenshots.

7. **30 specs, ~12 implemented** -- Of the 30 baseline specs, approximately 12 correspond to implemented features (client-management, contacts-sync, lead-management, request-management, pipeline, pipeline-insights, dashboard, my-work, entity-notes, notifications-activity, admin-settings, openregister-integration, product-catalog, prospect-discovery). The remaining 18 (kcc-werkplek, kennisbank, klantbeeld-360, etc.) are roadmap items. This is not an issue per se, but worth noting for context.

## Overall Assessment
**Conditional Pass** -- The app is structurally sound and well-architected. OpenSpec is clean (30 specs, 43 archives, 0 active changes). All 54 unit tests pass. All quality checks pass (PHPCS, PHPMD, Psalm, PHPStan). The codebase is substantial (60 PHP files, 43 Vue components, 10 controllers, 33 services, 4 dashboard widgets, 393 translation keys in en/nl). All 9 main pages render without crashes, and the overall UI is consistent with proper Cards/Table toggle, Add Item, and Actions patterns throughout.

The root route 404 is the most impactful issue -- it means direct navigation to the app base URL fails. The broken Documentation link and accessibility warnings are secondary concerns. The OpenRegister "not registered" errors are expected in an unconfigured environment and the app handles them gracefully with empty states.

Compared to the previous review (2026-03-20), the app is in similar functional state. The pipeline data inconsistency noted previously has been resolved (both Pipeline page and Admin Settings now consistently show no pipelines when unconfigured). No new regressions were observed.
