# Design: align-claims-and-first-hour

## Context

The 2026-07-05 audit cross-checked every outward claim against HEAD. This change is deliberately mechanical — each fix is small — but two items need design decisions: the licence element constraint and the demo-seed shape.

## Licence: the xsd constraint (RESOLVED 2026-07-05)

Company decision: Conduction apps are EUPL-1.2 (`LICENSE` already is). Re-verified upstream 2026-07-05: Nextcloud's app-info schema (`resources/app-info.xsd`, `xs:simpleType name="licence"`) **accepts `EUPL-1.2`** — the value was added by nextcloud/server PR #60212 ("feat(app-licenses): Add further compatible licenses for apps to use", master commit `fff79031`, 2026-05-07; stable31 backport `9b18e93b`), inside the SPDX enum block annotated `<!-- Requires Nextcloud minVersion >= 31 -->`. Verified present in tagged releases v33.0.5/v33.0.6/v34.0.1 and on the stable31/stable32 branch heads; tags up to and including v32.0.8 predate the backport. The App Store accepts it too (`EUPL-1.2` — "European Union Public Licence 1.2" in nextcloudappstore `core/fixtures/licenses.json`). The earlier "there is no EUPL value" finding was an artifact of the stale local dev checkout's xsd.

Treatment (PO decision 2026-07-05: "NC now supports EUPL so let's use that"):

- **Free-text surfaces → EUPL-1.2 everywhere**: README badge (line 13) → `license-EUPL--1.2` shield; info.xml English description (line 28) and Dutch description (line 51) → "under the EUPL-1.2 license" / "onder de EUPL-1.2-licentie".
- **`<licence>` element (line 56)**: `agpl` → `EUPL-1.2`. This is the specced outcome. Verify at apply time that the targeted NC version's shipped xsd carries the EUPL enum value (deployed instance's `resources/app-info.xsd`, not the stale dev checkout — fleet precedent: AppHost nav failed info.xsd for less).
- **Fallback (documented, NOT the default)**: only when the release must validate against an NC version whose xsd predates the EUPL value (tags ≤ v32.0.8 at verification time; 31/32 receive it via the already-merged stable31/stable32 backports in their next minors) — keep `agpl` with the adjacent XML comment `<!-- This NC version's schema predates the EUPL value; canonical licence is EUPL-1.2, see LICENSE -->`. EUPL-1.2's compatibility clause (Appendix) lists AGPL-3.0 as a compatible licence, which keeps that fallback declaration defensible rather than false.
- **Fleet-wide follow-up (out of scope)**: openregister and the other Conduction apps' info.xml also declare `agpl` today — the same `EUPL-1.2` flip applies fleet-wide and is tracked as a separate change.

## Claim rewrites (exact targets)

| Claim | Where (verified) | Rewrite |
|---|---|---|
| Unified Search as own feature | `README.md:94` | "Unified Search — clients, leads and requests appear in Nextcloud global search **via OpenRegister** (`lib/Search/ObjectsProvider.php`)" |
| Request-to-Case Bridge | `README.md:58`, info.xml features | Roadmap wording: "in development — see openspec change `semantic-handoff-emit` (kind-addressed handoff to the `ns#Case` implementer)". Un-roadmap it only when that change ships |
| Duplicate detection | `info.xml:20`, `README.md:48` | "Duplicate detection via OpenRegister master-data management" (in-app engine deleted in PR#332) |
| CSV + vCard import/export | `info.xml:21`, README Features | Drop "CSV"; keep vCard import/export (code exists: `ContactVcardWriterService`, contact sync) and any real CSV **export** path if verified during apply — claim only what greps |

## Overlay downgrade

`openspec/features.overlay.json` entry `omnichannel-registratie`: `"status": "stable"` → `"status": "beta"` plus a `statusReason` (or the overlay's equivalent field — follow the overlay schema) recording: outbound WhatsApp/SMS send has zero production callers and no UI surface; `SlaEngineService::dispatchNotification` defers every channel except `nextcloud-notification`; inbound webhooks are wired (`messagingWebhook#whatsapp|sms`). Summary texts (EN/NL) adjusted so they stop promising outbound reach ("Reach clients on WhatsApp and SMS") and describe inbound registration + consent logging, which is what ships. Downgrade is the **default**; wiring outbound is the owner's alternative and would be its own change.

## Dashboard: the null Satisfaction KPI

`src/manifest.json` Operational dashboard: widget def `{ "id": "satisfaction", "type": "custom", "title": "Customer Satisfaction" }` (~line 388), layout slot `{ "id": "17", "widgetId": "satisfaction", ... }` (~line 408), template map `"widget-satisfaction": "SatisfactionKpiWidget"` (~line 423). Root cause: `AnalyticsService.php:278` hardcodes `$responses = [];` (forms-leaf migration removed survey routes), so the widget is permanently null for every install.

Decision: **remove the widget def + layout slot from the default Operational dashboard** (and the registry entry if nothing else references it), leaving a `_note` in the manifest pointing at `customer-satisfaction-closed-loop` as the restoration owner. Removing beats a placeholder: a "coming soon" tile is another false claim. The grid slot (3 cols) is reflowed to the neighbouring KPIs. `customer-satisfaction-closed-loop` re-adds the widget with real data — referenced, not duplicated here.

## Demo seed

`lib/Command/SeedDemoDataCommand.php`, occ id `pipelinq:demo:seed`, mirroring procest's `SeedBezwaarBeroepCommand` (`procest:bezwaar:seed`) pattern:

- Symfony `Command` registered in info.xml; **idempotent** via stable demo UUIDs / a seeded marker — re-run updates instead of duplicating.
- Handles the occ anonymous-session gotcha the procest command documents (occ runs with no session; create objects with an admin/owner context explicitly).
- Seed set (small, coherent, Dutch-flavoured to match the market): ~5 clients (mix person/org), ~6 leads across pipeline stages, ~8 requests across statuses, ~12 contactmomenten across channels, linked so klantbeeld-360, dashboards, and lists all show something.
- Clearly marked demo data (name prefix or tag) so it is identifiable and removable; a `--remove` option deletes exactly the seeded objects.
- **Setup wizard**: exposed as an optional action through the `first-time-setup` change's `SetupController` `POST /api/setup/action/{actionId}` surface (ADR-042) — actionId `seed-demo-data`, admin-only, invoking the same service the command uses (one write path).

## docs/features.json

`kcc-werkplek` entry (~line 105): summary currently "@e2e exclude draft/unbuilt spec — … not yet implemented; no UI surface to test". The KCC werkplek shipped as the Customer Support nav host (menu-layout IA revision). Entry is rewritten to describe the shipped surface; while in the file, any other entry contradicting HEAD found during apply is fixed in the same batch (always-fix-preexisting), with the diff limited to factual status corrections.

## Risks / Trade-offs

- **Store-listing churn** — claim rewrites change the app-store description; acceptable, honesty outranks marketing continuity.
- **Demo data in production** — mitigated by explicit occ/setup opt-in, demo marking, and `--remove`.
- **Overlay downgrade optics** — beta with a reason reads worse than stable; that is the point (the overlay feeds public feature pages).
