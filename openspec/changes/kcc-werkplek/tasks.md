# Tasks: kcc-werkplek

> Implementation notes (ADR corrections applied during build):
> - **Seed data (§1):** ADR-037 forbids editing the monolith `lib/Settings/pipelinq_register.json`. Seeds were added as a NEW fragment `lib/Settings/register.d/10-kcc-werkplek.json` instead. The fleet-standard `components.objects[]` union rule was MISSING from `ConfigFileLoaderService::deepMergeConfig` (lists previously replaced), so a seed fragment would have wiped the monolith's 39 existing seeds; the additive union (objects by `@self` identity, register `schemas[]` by value) was added + unit-tested.
> - **Backend (§2):** Real OR API is `findAll(['filters'=>...])` / `saveObject($reg,$schema,$obj)` / `updateObject($reg,$schema,$id,$obj)` (NOT `findObjects`). `KccWerkplekService` reuses `RoutingService::getAgentWorkload`. Controller method names are `state()` / `setAvailability()` (route slug `kccWerkplek#…`).
> - **Frontend (§3-5):** This app has NO `src/router/index.js` or `src/navigation/MainMenu.vue`; it is a declarative manifest SPA (ADR-036). The 5-component split was consolidated into one bespoke registry page `src/views/werkplek/KccWerkplek.vue` + a manifest fragment `src/manifest.d/10-kcc-werkplek.json` (page + menu) + a `registry.js` entry. App imports come from `@nextcloud/vue` (the established convention here, e.g. MyWork.vue).
> - **Deferred (§3.4):** Inline knowledge-base search depends on the `kennisartikel` schema + `KennisbankService.submitFeedback()`, both REMOVED from pipelinq by `migrate-kennisbank-to-xwiki-leaf` (kennisbank now lives in an external XWiki leaf). No in-app store/service exists to query, so this panel is deferred — see §3.4.

## 0. Deduplication Check

- [x] 0.1 Searched for overlap with `ObjectService`/`RegisterService`/`SchemaService`/`ConfigurationService` and existing pipelinq services. Findings: workspace-state aggregation is domain-specific (not in OR core). `RoutingService` already provides agent workload counting and is REUSED rather than reimplemented. No overlap with a generic OR service.
- [x] 0.2 Verified `src/components/CallTimer.vue` exists and is reused in the werkplek view (emits `stopped` with an ISO-8601 duration) — no new timer created.
- [ ] 0.3 DEFERRED — `KennisbankService.submitFeedback()` does NOT exist in pipelinq (kennisbank was migrated out to an XWiki leaf, `migrate-kennisbank-to-xwiki-leaf`). No feedback logic to reuse or duplicate; the dependent knowledge-search panel is deferred (see §3.4).

## 1. Seed Data

- [x] 1.1 Added 3 `queue` seed objects (ADR-037: in the NEW fragment `lib/Settings/register.d/10-kcc-werkplek.json`, NOT the monolith) — slugs `queue-algemene-zaken`, `queue-vergunningen`, `queue-wmo-zorg`.
- [x] 1.2 Added 3 `agentProfile` seed objects to the fragment — slugs `agent-jan-de-vries`, `agent-fatima-el-amrani`, `agent-pieter-bakker`.
- [x] 1.3 Added 3 `skill` seed objects to the fragment — slugs `skill-vergunningen`, `skill-wmo-zorg`, `skill-algemene-dienstverlening`.
- [x] 1.4 All seed entries use the `@self` envelope with `register: "pipelinq"`, the correct schema name, and a unique slug (JSON validated).
- [x] 1.5 Idempotency: added the fleet-standard additive `components.objects[]` union to `ConfigFileLoaderService::deepMergeConfig` (de-dup by `@self` register+schema+slug; same identity replaces, no duplicate) + register `schemas[]` union by value + 4 unit tests. This both preserves the monolith's existing 39 seeds and makes re-import idempotent by slug.

## 2. Backend

- [x] 2.1 Created `lib/Service/KccWerkplekService.php`:
  - `getWorkspaceState(string $userId): array` — `ObjectService::findAll(['filters'=>...])` (the real OR API, NOT `findObjects`) for requests (assignee, status filtered to new/in_progress PHP-side), tasks (assigneeUserId, status open/in_behandeling), agentProfile (userId), per-queue open-request counts; `workload` reuses `RoutingService::getAgentWorkload`.
  - `setAvailability(string $userId, bool $available): array` — finds the profile by userId, `updateObject(...)` to set `isAvailable`, creates one via `saveObject(...)` when absent.
  - `@spec` on file header + all public methods.
- [x] 2.2 Created `lib/Controller/KccWerkplekController.php`:
  - `GET /api/kcc-werkplek/state` → `state()`; `PUT /api/kcc-werkplek/availability` → `setAvailability()` (reads `isAvailable` bool from body, validates type). Both `#[NoAdminRequired]`; agent UID derived from `IUserSession` (IDOR-safe self-scope), 401 when unauthenticated.
  - All exceptions → `JSONResponse(['message' => 'Operation failed'], 500)` + `logger->error(['exception'=>$e])`; never leaks `$e->getMessage()`.
  - `@spec` on file header + all public methods.
- [x] 2.3 Added routes to `appinfo/routes.php` (slug `kccWerkplek#state` / `kccWerkplek#setAvailability`) immediately after `routing#getSuggestions`, before the SPA catch-all; no `{id}` wildcards involved.

## 3. Frontend Components

> ADR-036 correction: this app is a declarative manifest SPA with no per-component nav/router files. The 5 sub-components were consolidated into ONE bespoke registry page `src/views/werkplek/KccWerkplek.vue` (inbox + contactmoment panel + availability toggle + queue overview as sections). Established app convention imports NC primitives from `@nextcloud/vue` (see MyWork.vue), so §8.5 does not apply here.

- [x] 3.1 Agent availability toggle (the header "Available/Unavailable" button in `KccWerkplek.vue`): `axios.put('/apps/pipelinq/api/kcc-werkplek/availability', { isAvailable })`, optimistic flip reverted on error, `showError` feedback on failure, SPDX header, strings via `t('pipelinq', …)`.
- [x] 3.2 Inbox sections "Requests" + "Tasks": priority-sorted lists, overdue task deadlines highlighted with `var(--color-error)`, row click selects the item into the form, "No open items" empty state. (Rendered as accessible lists rather than `CnDataTable` to fit the narrow side panel; same data + sort + empty-state contract.)
- [x] 3.3 Contactmoment registration panel: channel `NcSelect` (real schema enum `telefoon/email/balie/chat/social/brief`), `CallTimer` shown only for `telefoon` (auto-fills ISO duration on stop), subject (required) + summary `NcTextArea` + outcome `NcSelect`, "Register" → POST to OR objects API; the `agent` field is intentionally NOT sent (backend/owner records the user — IDOR-safe, ADR-005); save wrapped in try/catch with inline feedback; SPDX + translated. (The selected inbox item pre-fills the form in place of a separate "New task" `CnFormDialog`, which depended on the consolidated split.)
- [ ] 3.4 DEFERRED — inline knowledge-base search. Depends on the `kennisartikel` schema + `KennisbankService.submitFeedback()`, both removed from pipelinq by `migrate-kennisbank-to-xwiki-leaf` (kennisbank lives in an external XWiki leaf; no `kennisartikel_schema` in settings, no in-app store/service). Cannot be built against a schema/service the app no longer ships. The view documents this gap inline.

## 4. Main Workspace View

- [x] 4.1 Created `src/views/werkplek/KccWerkplek.vue`: three-panel CSS Grid (`320px 1fr 280px`) collapsing to a single column ≤1024px; header bar with workload + availability toggle; fetches state via `axios.get('/apps/pipelinq/api/kcc-werkplek/state')` in `created()`; `NcLoadingIcon` while loading; inbox selection feeds the contactmoment form; all async wrapped in try/catch with user-facing error + retry; SPDX + translated.

## 5. Navigation and Routing

> ADR-036 correction: `src/router/index.js` and `src/navigation/MainMenu.vue` DO NOT EXIST in this app — routing + nav are declared in the manifest.

- [x] 5.1 Added the `/werkplek` page (`id: KccWerkplek`, `type: custom`, `component: KccWerkplekView`) to the manifest fragment `src/manifest.d/10-kcc-werkplek.json`; component registered in `src/registry.js` as a `kind: "page"` entry.
- [x] 5.2 Added the "KCC Werkplek" menu entry to the same manifest fragment as the first item (`order: 5`, icon `icon-comment` — `mdi-headset` is not a valid NC menu icon class in this manifest), routing to the `KccWerkplek` page.

## 6. Store Registration

- [x] 6.1 `queue`, `agentProfile`, `skill` were already registered in `src/store/store.js`; added the missing `task` registration (needed by the inbox). All use `objectStore.registerObjectType(...)` (the app's `createObjectStore`-backed pattern).
- [x] 6.2 Type names follow the app's existing convention (`agentProfile`, `queue`, `skill`, `task`) consistently across store + view; no stray camelCase/kebab divergence introduced.

## 7. Translations

- [x] 7.1 Added all new user-visible strings to `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js` (and `l10n/en_US.json` for parity): KCC Werkplek, Workload, Available, Inbox, No open items, Register contactmoment, Clear, Contactmoment registered, Failed to load the workspace, Could not update your availability, Contactmoment is not configured, Could not register the contactmoment. Pre-existing keys reused: Unavailable, Retry, Requests, Tasks, Channel, Subject, Summary, Outcome, Register, Queues, Untitled. (Kennis/feedback strings dropped with §3.4.) Keys are English; Dutch in nl.*.
- [x] 7.2 Verified `en.json` and `nl.json` share all the new keys (the only en/nl gaps are pre-existing, unrelated activity-timeline strings); all four l10n files validate (`node --check` / `json.load`).

## 8. Pre-commit Verification

- [x] 8.1 SPDX present on `src/views/werkplek/KccWerkplek.vue`, `lib/Controller/KccWerkplekController.php`, `lib/Service/KccWerkplekService.php`.
- [x] 8.2 ObjectService calls verified: `findAll(['filters'=>...])` (1-arg params shape — the real OR API in this codebase), `saveObject($reg,$schema,$obj)`, `updateObject($reg,$schema,$id,$obj)`.
- [x] 8.3 `getMessage()` appears only in a guardrail comment in the controller — never in a response body (verified).
- [x] 8.4 `setAvailability()` has NO admin check — it is correctly open to all authenticated agents and self-scoped via `IUserSession`.
- [x] 8.5 N/A — app convention imports NC primitives from `@nextcloud/vue`; no `@conduction/nextcloud-vue` requirement in this app's views (matches MyWork.vue and peers).
- [x] 8.6 Every `<Nc*>`/`<CallTimer>` used in the werkplek template is imported AND registered in `components: {}` (eslint passes with 0 errors).

## 9. Verification

- [x] 9.1 `npm run build` succeeds (0 errors; only the pre-existing bundle-size performance warnings). eslint on the new files: 0 errors. `appinfo/info.xml` bumped 0.2.28 → 0.2.29 (bundle changed; immutable-cache-bust).
- [x] 9.2 Response shape verified by `KccWerkplekServiceTest`/`KccWerkplekControllerTest` (agentProfile, assignedRequests, openTasks, queueCounts, workload). Live curl deferred to reviewer env (no running NC in the build worktree).
- [x] 9.3 401-without-auth path covered by `testStateRequiresAuthentication` / `testSetAvailabilityRequiresAuthentication`.
- [ ] 9.4 DEFERRED to reviewer/QA env — browser smoke of `/werkplek` (no running NC instance in the build worktree). Knowledge-search portion is N/A (see §3.4).
- [ ] 9.5 DEFERRED to reviewer/QA env — live toggle round-trip; unit-tested via `testSetAvailabilityUpdatesExistingProfile` / `…CreatesProfileWhenAbsent`.
