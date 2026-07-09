# Proposal: align-claims-and-first-hour

kind: conformance + onboarding — make every outward claim (licence, README, info.xml, features overlay, docs/features.json) match implementation reality, remove the permanently-null KPI from the default dashboard, and give a fresh install a first hour that shows a working product (demo seed).

All findings verified against HEAD on 2026-07-05.

## Problem

**Claims that are false or stale:**

1. **Licence contradiction** — `LICENSE` is EUPL-1.2 and `README.md:261` says EUPL-1.2, but `appinfo/info.xml:56` declares `<licence>agpl</licence>`, the info.xml descriptions (lines 28/51) say "under the AGPL license", and the `README.md:13` badge says AGPL-3.0. Company decision: apps are EUPL-1.2. Constraint re-verified upstream 2026-07-05: Nextcloud's `resources/app-info.xsd` licence enum **now accepts `EUPL-1.2`** (added by nextcloud/server PR #60212, 2026-05-07, backported to stable31/stable32; present in tagged releases from v33.0.5; App Store fixtures also list `EUPL-1.2`) — the earlier "not a valid element value" finding came from the stale local dev checkout's xsd; see design.md §Licence.
2. **Unified Search** (`README.md:94`) — claimed as a pipelinq feature; pipelinq ships zero search code. Fleet decision: OR provides search centrally via `lib/Search/ObjectsProvider.php`. The claim must say "via OpenRegister".
3. **Request-to-Case Bridge** (`README.md:58`, info.xml) — zero implementing code at HEAD. The `semantic-handoff-emit` change (parallel) will build it; until it ships the claim is roadmap, not feature.
4. **Duplicate detection** (`info.xml:20`, `README.md:48`) — the in-app engine was deleted in PR#332; duplicate detection now lives in OR MDM. Re-point or remove.
5. **CSV import** (`info.xml:21`, README "Import and export — bulk operations with CSV and vCard support") — no CSV import exists (vCard code does: `ContactVcardWriterService` et al.). Remove the CSV-import claim or spec it; default: remove.
6. **Overlay overstatement** — `openspec/features.overlay.json` marks `omnichannel-registratie` as `stable` while WhatsApp/SMS **outbound** send() has zero production callers, no UI surface, and `SlaEngineService::dispatchNotification` (~line 836) returns `'deferred:'` for every channel except nextcloud-notification. Inbound webhooks ARE wired (`messagingWebhook#whatsapp|sms`). Stable is not the truth; beta with a stated reason is.
7. **docs/features.json staleness** — `kcc-werkplek` (line ~107) still says "not yet implemented; no UI surface to test" while the KCC werkplek shipped (it is the Customer Support nav host per the IA revision note in `src/menu-layout.json`).

**First-hour gaps:**

8. **Permanently-null KPI on the default dashboard** — the Satisfaction widget (`SatisfactionKpiWidget`, widget id `satisfaction` on the Operational dashboard, `src/manifest.json` ~388/408) always renders empty because `AnalyticsService.php:278` hardcodes `$responses = [];` after the forms-leaf migration removed survey routes. The active `customer-satisfaction-closed-loop` change will re-source CSAT — this change only hides/replaces the widget until then (no duplication of that change).
9. **No demo data** — `lib/Repair/` and `lib/Command/` contain no demo-seed step (verified). A first-time evaluator sees empty lists everywhere. Procest solved this with `SeedBezwaarBeroepCommand` (`procest:bezwaar:seed`, idempotent occ command).

## Solution

1. **Licence conformance** — README badge → EUPL-1.2; info.xml description strings → EUPL-1.2; `<licence>` element → `EUPL-1.2` (PO decision 2026-07-05: "NC now supports EUPL so let's use that"; the token is in the upstream xsd enum since 2026-05-07 and in the App Store's accepted licenses). The former "keep `agpl` + XML comment" treatment survives only as the documented fallback for NC versions whose xsd predates the EUPL value (design.md §Licence pins the versions).
2. **Claim rewrites** — Unified Search → "provided via OpenRegister"; Request-to-Case Bridge → roadmap wording pointing at `semantic-handoff-emit`; duplicate detection → "via OpenRegister master-data management"; CSV-import claim removed (vCard import/export claim stays — it has code).
3. **Overlay honesty** — `omnichannel-registratie` `stable` → `beta` with the reason recorded (outbound WhatsApp/SMS unwired: no production send() callers, SLA dispatch defers all non-nextcloud channels). Presented as the default; the alternative (wiring outbound send) is noted for the owner but NOT specced here.
4. **Dashboard truth** — remove/replace the Satisfaction KPI slot on the Operational dashboard until `customer-satisfaction-closed-loop` restores real data (referenced, not duplicated).
5. **Demo seed** — `pipelinq:demo:seed` occ command (mirroring procest's `SeedBezwaarBeroepCommand` pattern: idempotent, admin-context aware) seeding a small coherent demo set (clients, leads, requests, contactmomenten), exposed as an **optional** setup-wizard action per ADR-042 via the `first-time-setup` change's SetupController action mechanism (referenced, not duplicated).
6. **docs/features.json** — kcc-werkplek entry updated to shipped reality.

## Scope

- `appinfo/info.xml`, `README.md` (claims + badge)
- `openspec/features.overlay.json` (omnichannel status + reason)
- `src/manifest.json` (Operational dashboard satisfaction slot), `src/registry.js`
- `lib/Command/SeedDemoDataCommand.php` + `appinfo/info.xml` command registration + optional setup action wiring
- `docs/features.json`

**Depends on:** `semantic-handoff-emit` (claim re-point target), `customer-satisfaction-closed-loop` (KPI restoration owner), `first-time-setup` (setup action surface, ADR-042), OR MDM + OR `lib/Search/ObjectsProvider.php` (claim substance).

## Out of Scope

- Wiring outbound WhatsApp/SMS send (owner may choose this instead of the downgrade — separate change if so)
- Re-sourcing CSAT data (`customer-satisfaction-closed-loop` owns it)
- Building the Request-to-Case bridge (`semantic-handoff-emit` owns it)
- Any CSV-import implementation (removed claim; spec it later only if demanded)

## Success Criteria

- No document in the repo claims AGPL as the project licence in prose or badge; the `<licence>` element declares `EUPL-1.2` (schema-valid against an xsd carrying the EUPL enum value) per the owner's 2026-07-05 decision
- README/info.xml contain no feature claim without code behind it (search "via OpenRegister"; bridge marked roadmap → `semantic-handoff-emit`; duplicate detection re-pointed at OR MDM; no CSV-import claim)
- `features.overlay.json` reports `omnichannel-registratie` as beta with a machine-readable reason
- The default Operational dashboard renders no permanently-empty widget
- `occ pipelinq:demo:seed` on a clean install produces browsable clients, leads, requests, and contactmomenten; running it twice creates no duplicates; the setup wizard offers it as an optional step
- `docs/features.json` matches shipped reality for kcc-werkplek
