# Proposal: pipelinq-integration-config-to-settings

kind: navigation/IA refactor (declarative manifest only) — cites ADR-037 (modular config fragments + canonical manifest conventions), ADR-022 (apps-consume-or-abstractions — these surfaces stay routable, no controller change), ADR-012 (deduplication).

## Summary

pipelinq currently exposes four integration/config surfaces as **top-level transactional
navigation** items in `src/manifest.json` `menu`:

| Menu entry id | Label | Route | order | Nature |
| --- | --- | --- | --- | --- |
| `Pipelines` | Pipelines | `Pipelines` (`/pipelines`) | 200 | **Config** — pipeline DEFINITIONS (`schema: pipeline`) |
| `ExportJobs` | BI export | `ExportJobs` (`/export/jobs`) | 215 | **Integration** — BI export jobs / data-warehouse sink |
| `StufEndpoints` | StUF endpoints | `StufEndpoints` (`/stuf/endpoints`) | 216 | **Integration** — StUF endpoint configuration |
| `StufAuditLog` | StUF audit log | `StufAuditLog` (`/stuf/audit-log`) | 217 | **Integration (read-only log)** — StUF message audit trail |

These are configuration and integration surfaces, not day-to-day transactional work. Per the
canonical IA pattern (the docudesk/procest "good model": config / types / definitions /
integrations / retention belong under a **Settings** group, not in the top-level main list),
they should move into the `CnAppNav` settings (gear-icon) foldout. Each page stays **routable**
(its `pages[]` entry, route, type, component, and config are untouched), so deep links,
bookmarks, and in-page action navigations continue to resolve. This is the same
**demote-not-delete** behaviour decidesk used in `ia-six-item-nav` / `decidesk-retire-motions-nav`.

The change distinguishes **"Pipelines"** (pipeline *definitions* = configuration → moves to
Settings) from **"Pipeline"** (`menu` id `Pipeline`, route `/pipeline`, the operational
deal/kanban board → **stays** as top-level transactional nav). No other top-level item is moved.
The read-only **StUF audit log** is grouped under an **Integrations** caption inside Settings.

The pipelinq settings foldout is currently **empty** (no `menu` entry carries
`section: "settings"` today, even though a `SyncSettings` page exists). This change therefore
also establishes the Settings foldout structure using the schema-supported `section: "settings"`
field and a `type: "caption"` divider for the **Integrations** sub-group, matching the procest
exemplar (`*Menu` entries with `section: "settings"` whose `route` points at the still-routable
page).

**Depends on:** ADR-037 (declarative-manifest conventions, `section` enum `main|footer|settings`),
ADR-022 (no redundant controllers — surfaces remain consumer pages over OpenRegister), ADR-012
(deduplication). No cross-app interface contract is invoked; no schema, controller, route-table,
or data change.

## Deduplication rationale (ADR-012)

Phase 0 inspection of `src/manifest.json` and `src/manifest.d/*.json` confirms:

- The four target surfaces (`Pipelines`, `ExportJobs`, `StufEndpoints`, `StufAuditLog`) each
  exist exactly once as a `menu` entry and once as a `pages[]` entry. There is no duplicate or
  competing config surface for any of them.
- There is **no** existing `section: "settings"` menu entry and **no** `caption` divider in the
  pipelinq menu today, so this change is not duplicating an existing Settings group — it
  establishes the first one rather than re-creating one.
- The `nc-vue` app-manifest-v2 schema already supports `section: "settings"` and
  `type: "caption"` on a `menuItem`; this change reuses that built-in capability rather than
  inventing a new grouping mechanism. The procest manifest is the in-fleet precedent.
- No new schema, register fragment (`lib/Settings/register.d/*.json`), controller, route, or
  `pages[]` entry is added; the four pages are reused as-is. Nothing is removed from `pages[]`.

The change is purely an IA relocation of four `menu` entries plus one new `caption` divider — it
adds no capability and removes no capability, it only moves where four existing routable surfaces
render in the navigation.
