# ADR-002: Information Architecture — Rail, Tabs, Tenants

**Status:** accepted
**Scope:** pipelinq
**Applies to:** specs, design, frontend
**Last updated:** 2026-05-23

## Context

Pipelinq is Conduction's unified **relationship + interaction** app. Three personas converge on the same data model:

- **B2B sales** — pipeline, deals, quoting, time/projects feeding shillinq.
- **KCC / government servicedesk** — contactmomenten, terugbel, klachten, AVG-verzoeken, zaak-bridge.
- **Retail / hospitality POS** — cart, tender, receipt, end-of-day post.

The current spec corpus is large (86 specs covered by IA v1) and growing. Earlier informal information-architecture attempts (v0) created per-tenant rail variants and let every spec compete for a top-level menu item or its own detail tab. The result was menu sprawl, duplicate sub-pages, and per-tenant frontend forks that became impossible to keep in sync.

The IA v1 draft (`/tmp/ia-pipelinq.md`, 2026-05-22) consolidated all 86 specs into an **eight-item top-level rail** plus a footer `Beheer` settings drawer (six "domain" menus — Mijn werk, Contacten, Pipeline, Klantcontact, Klachten & Verzoeken, Catalogus — plus two persona-mode menus, Kassa and Projecten & Tijd, that hide under tenant mode; Beheer is the seventh + eighth surface counted as one drawer). The rail is the same for every tenant; menu items hide based on tenant mode. Cross-app bridges (shillinq-*, ZGW, StUF, BRP, e-mail/agenda, CTI, WhatsApp/SMS, MijnOverheid) live exclusively under `Beheer → Integraties` and surface their data on existing entity detail pages.

Because every spec author needs to know **where their feature lives** before they can write requirements, the IA design rules need to be lifted out of the draft document and pinned as an ADR. Without an ADR, every new spec re-litigates rail placement, tabs and tenant gating.

## Decision

Adopt the following five cross-cutting Information Architecture rules. They apply to every pipelinq spec — existing, in-flight, or future — and are normative when reading the IA mapping table in the draft IA document.

---

### R1 — POS sub-features extend `pos-transaction-core`, never own menu items

**Rule.** All `pos-*` specs are sub-mechanisms of one transaction stream and MUST live as sub-pages, detail-tabs, widgets, actions or settings inside the `Kassa` menu. A new POS spec MUST declare its placement under `pos-transaction-core` in its frontmatter. No `pos-*` spec ever earns a rail entry.

**Rationale.** POS depth (BTW engine, kassakoppeling audit, split-tender, staff PIN, receipt engine, payment-provider adapter, refund/return, EOD posting, barcode scan, customer link, cash management, product catalogue) was originally drafted as twelve standalone specs. Promoting any of them to the rail would split the cashier's working surface and fragment the transaction lifecycle. POS users only ever leave the `Kassascherm` to look at history (`Bonnen`), float (`Kasbeheer`) or end-of-day (`Einde-dag`).

**How to apply.** When proposing a `pos-*` change, set its placement to one of `SUB_PAGE` (under `/kassa/...`), `DETAIL_TAB` (on `/kassa/bonnen/{id}`), `WIDGET` (on `/kassa`), `ACTION` (button on the cashier surface) or `SETTING` (`Beheer → Kassa`). Reject reviews that add a new top-level menu item for POS depth.

---

### R2 — Tenant-mode is a SETTING, not a menu

**Rule.** B2B-sales, KCC-government, Retail-POS and ZZP-solo are tenant **modes** configured under `Beheer → Algemeen`. The **same rail renders for every tenant**; menu items hide based on mode. Per-tenant rail variants are forbidden.

**Rationale.** v0 forked the rail per tenant. That made spec authors duplicate features across "the KCC rail" and "the B2B rail", and made every menu change a multi-file edit. The single-rail model also lets multi-mode tenants (e.g. a municipality that runs KCC + retail in the same instance) get the union of menus without code changes.

**How to apply.**
- ZZP-solo hides `Klantcontact` and `Kassa`.
- KCC-government hides `Kassa` and collapses `Pipeline`.
- B2B-sales hides `Kassa` and the overheid-only sub-pages (BSN/BRP lookup, Burgerportaal intake).
- Retail-POS shows the full rail.

Conditional visibility is the only allowed mechanism. New specs that introduce tenant-specific behaviour MUST express it as conditional rendering inside the canonical surface, not as a parallel surface.

---

### R3 — Cross-app bridges surface as SETTING + status, never a top-level menu

**Rule.** All cross-system bridges — `pipelinq-*-to-shillinq-*`, `zgw-api-bridge`, `stuf-zkn-bg-adapter`, `burgerportaal-mijnoverheid-bridge`, `email-calendar-sync`, `cti-screenpop-adapter`, `whatsapp-sms-channel-adapter`, `website-lead-widget`, future ERP/HR/BI adapters — MUST live under `Beheer → Integraties` (or `Beheer → Adapters` for hardware). The data they push or pull MUST surface on existing entity detail pages as a tab or status row.

**Rationale.** Bridges are infrastructure, not destinations. Users do not navigate to "the ZGW bridge" — they open a klacht and see a `Zaak` tab. Treating bridges as menus would punish users for every integration the admin enables, and would break the persona-mode rail (R2). Centralising bridges under `Beheer → Integraties` also gives ops one place to inspect run-logs, credentials and webhook health.

**How to apply.** A new bridge spec MUST specify two surfaces:

1. **Config surface** — a `SETTING` pane under `Beheer → Integraties` (or `Beheer → Adapters` for terminals/scanners).
2. **Data surface** — a `DETAIL_TAB`, `WIDGET`, `ACTION` or status row on the entity whose record the bridge enriches (e.g. WIP-status tab on `/projecten/{id}`, Zaak tab on `/klantcontact/contactmomenten/{id}`).

Reviews MUST reject bridge specs that propose a new top-level menu.

---

### R4 — One canonical surface per concept; deltas attach as tabs, widgets, or read/write splits

**Rule.** Where two or more specs cover the same conceptual surface (e.g. `product-catalog` + `product-service-catalog`; `terugbel-taakbeheer` + the dated delta; `kennisbank` + the dated delta), the canonical spec owns the sub-page. Delta specs MUST contribute one of: a detail-tab, a widget, an action, or a read-side / write-side split (e.g. kennisbank read under `Klantcontact`, write under `Catalogus`). Duplicate sub-pages are a bug.

**Rationale.** The IA mapping table is the source of truth for canonicality. Two specs sharing a concept and each owning a top-level URL is how dead pages, divergent labels and broken cross-links happen — the symptom that motivated the v1 mapping exercise.

**How to apply.** Before opening a spec, search the IA mapping table for the closest existing concept. If one exists, declare your spec as a delta and pick a non-`SUB_PAGE` placement. If two specs already share a `SUB_PAGE`, file a follow-up to consolidate before adding a third.

---

### R5 — Detail pages have at most 8 tabs; overflow folds into "Tijdlijn" or a "Meer" menu

**Rule.** Any entity detail page MUST render at most eight tabs in any one tenant mode. Tabs beyond eight MUST either:

- Fold into the canonical `Tijdlijn` tab (for chronological content), OR
- Move behind a `Meer` overflow menu, OR
- Be promoted to their own sub-page (taking the spec out of the tab budget).

A new spec MUST NOT add more than one new tab to any one detail page. If a third tab is required, that spec owns a sub-page instead.

**Rationale.** Worked example: the contact-detail page maps to eleven tabs (Overzicht, Tijdlijn, Contactmomenten, Deals, Klachten, Aankopen, Projecten, Bestanden, Notities, BSN/BRP, AVG). BSN/BRP and AVG are conditional under R2 (overheid-tenant only), so they disappear in B2B/Retail. Bestanden + Notities fold into a single "Documenten & notities" tab. Aankopen + Projecten swap based on tenant mode (Retail surfaces Aankopen prominently; service orgs surface Projecten). Net: at most eight visible tabs in any one mode. Tab sprawl beyond eight is the strongest predictor of a cluttered detail page and is what triggered the IA refactor.

**How to apply.** Before adding a tab in a spec, count the existing tabs on the target detail page (per tenant mode). If the count is already eight, either fold into Tijdlijn, hide behind `Meer`, or move the feature to a sub-page. Document the choice in the spec's design section.

---

## Consequences

- **Spec frontmatter MUST declare placement.** Every new spec MUST include an IA placement (`TOP_MENU` / `SUB_PAGE` / `DETAIL_TAB` / `WIDGET` / `ACTION` / `SETTING` / `INFRA` / `CROSS_APP`) and reference the parent menu or detail page. `TOP_MENU` is reserved — the eight rail items are frozen; new ones require an ADR amendment.
- **Reviewers reject menu sprawl.** Any spec that adds a ninth top-level menu, a per-tenant rail fork, a bridge-as-menu, a duplicate sub-page, or a ninth detail-page tab MUST be revised before merge.
- **Frontend rendering follows tenant mode at runtime.** The rail component reads tenant mode from `Beheer → Algemeen` and hides menus per R2; it does not render alternative rails.
- **The IA mapping table in the draft IA document is the placement registry.** When two specs disagree on placement, the mapping table wins. A spec that wants to override the table MUST file a change against the table first.
- **POS, bridges, and conditional tabs are constrained.** R1, R3 and R5 explicitly forbid common spec patterns ("I want my own menu", "my bridge is important enough to surface", "just one more tab"). Spec reviewers can point at this ADR rather than re-litigating.

## Exceptions

- **Beheer** is a footer/gear drawer, not a main-rail item, and so does not count against the eight-item rail.
- The eight rail items themselves (Mijn werk, Contacten, Pipeline, Klantcontact, Klachten & Verzoeken, Kassa, Projecten & Tijd, Catalogus) are not specs — they are IA primitives owned by this ADR. Adding or renaming a rail item requires an ADR amendment, not a spec.

## References

- IA draft v1 — `/tmp/ia-pipelinq.md` (Conduction-internal, dated 2026-05-22). Contains the full 86-spec mapping table and per-menu sub-architecture that this ADR turns into normative rules.
- Cross-app data flow into shillinq (WIP / AP / Ledger) — see `pipelinq-*-to-shillinq-*` specs.
- Government bridges — see `zgw-api-bridge`, `stuf-zkn-bg-adapter`, `bsn-validatie-en-brp-lookup`, `burgerportaal-mijnoverheid-bridge` specs.
