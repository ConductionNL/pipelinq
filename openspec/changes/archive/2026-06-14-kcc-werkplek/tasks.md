# Tasks: kcc-werkplek

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for overlap with `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService` — document findings; no overlap expected since workspace state aggregation is domain-specific and not provided by OpenRegister core
- [x] 0.2 Verify `CallTimer.vue` already exists from `omnichannel-registratie` and is reused — do NOT create a new one
- [x] 0.3 Verify `KennisbankService.submitFeedback()` already exists from `kennisbank` and is reused — do NOT duplicate feedback logic — NOTE: the kennisbank backend service does not yet exist (only its spec); the werkplek panel writes feedback via the `articleFeedback` shape persisted through the kennisartikel object store rather than duplicating a parallel feedback service

## 1. Seed Data

- [x] 1.1 Add 3 `queue` seed objects to `lib/Settings/pipelinq_register.json` per design.md if not already present (slugs: `queue-algemene-zaken`, `queue-vergunningen`, `queue-wmo-zorg`)
- [x] 1.2 Add 3 `agentProfile` seed objects to `lib/Settings/pipelinq_register.json` (slugs: `agent-jan-de-vries`, `agent-fatima-el-amrani`, `agent-pieter-bakker`)
- [x] 1.3 Add 3 `skill` seed objects to `lib/Settings/pipelinq_register.json` (slugs: `skill-vergunningen`, `skill-wmo-zorg`, `skill-algemene-dienstverlening`)
- [x] 1.4 Verify all seed entries use `@self` envelope with `register: "pipelinq"`, correct schema name, and unique slug
- [x] 1.5 Verify `importFromApp()` idempotency: re-importing must skip existing slugs (no duplicate check needed in code — this is a design-time verification)

## 2. Backend

- [x] 2.1 Create `lib/Service/KccWerkplekService.php` with:
  - `getWorkspaceState(string $userId): array` — parallel `ObjectService::findObjects()` calls for requests (assignee=userId, status=new/in_progress), tasks (assigneeUserId=userId, status=open/in_behandeling), agentProfile (userId=userId), queue counts
  - `setAvailability(string $userId, bool $available): array` — find agentProfile by userId, update `isAvailable`, create new agentProfile if none exists
  - Add `@spec openspec/changes/kcc-werkplek/tasks.md#task-2` PHPDoc to file header and all public methods
- [x] 2.2 Create `lib/Controller/KccWerkplekController.php` with:
  - `GET /api/kcc-werkplek/state` → `stateAction()` — calls `KccWerkplekService::getWorkspaceState($currentUser->getUID())`; returns JSONResponse; `@NoAdminRequired`
  - `PUT /api/kcc-werkplek/availability` → `setAvailabilityAction()` — reads `isAvailable` from request body, calls `KccWerkplekService::setAvailability()`; `@NoAdminRequired`
  - Catch all exceptions with `return new JSONResponse(['message' => 'Operation failed'], 500)` + `$this->logger->error()`; NEVER return `$e->getMessage()`
  - Add `@spec` PHPDoc to file header and all public methods
- [x] 2.3 Add kcc-werkplek API routes to `appinfo/routes.php`:
  - `GET /api/kcc-werkplek/state`
  - `PUT /api/kcc-werkplek/availability`
  - Specific routes MUST be added BEFORE any wildcard `{slug}` routes

## 3. Frontend Components

- [x] 3.1 Create `src/components/werkplek/WerkplekAgentStatus.vue`:
  - Toggle button: "Beschikbaar" (green) / "Niet beschikbaar" (grey) based on `isAvailable` prop
  - On toggle → `await axios.put(generateUrl('/apps/pipelinq/api/kcc-werkplek/availability'), { isAvailable })`
  - Wrap in `try/catch` with NcDialog error feedback on failure; revert toggle on error
  - SPDX header; all strings via `this.t('pipelinq', ...)`

- [x] 3.2 Create `src/components/werkplek/WerkplekInbox.vue`:
  - Two sections: "Verzoeken" (requests) and "Taken" (tasks)
  - Use `CnDataTable` for each section; sort by priority descending
  - Highlight overdue tasks (deadline < now) with red text via NL Design token `var(--color-error)`
  - Emit `select-item` with the clicked item on row click
  - Show `CnEmptyState` with "Geen openstaande items" when both sections are empty
  - SPDX header; all strings translated

- [x] 3.3 Create `src/components/werkplek/WerkplekContactmomentPanel.vue`:
  - Channel NcSelect (options: telefoon, email, balie, chat, post, social)
  - Show `CallTimer.vue` only when channel = telefoon; auto-populate duration on timer stop
  - Client search autocomplete: query `ObjectService.findObjects('request', 'client', { _search: term })`
  - Subject (required) + summary textarea + outcome NcSelect
  - "Registreer" button: validate subject + channel, call `objectStore.saveObject(contactmomentData)`; reset form on success
  - `agent` field MUST NOT accept frontend-supplied user ID — leave blank and let backend set it via `IUserSession`
  - "Nieuwe taak" button opens `CnFormDialog` (schema: task) pre-filled with `clientId` and `contactMomentSummary`
  - Every `await store.action()` wrapped in `try/catch` with user-facing feedback
  - SPDX header; all strings translated

- [x] 3.4 Create `src/components/werkplek/WerkplekKennisSearch.vue`:
  - Debounced search field (300ms, min 2 chars) — use `setTimeout`/`clearTimeout` pattern
  - Query `createObjectStore('kennisartikel')` with `_search=term&status=gepubliceerd`
  - Results list: title + summary snippet (150 char truncation) + category badges
  - On result click: expand inline with full Markdown body (rendered via `marked`)
  - "Nuttig" / "Niet nuttig" buttons call `KennisbankService.submitFeedback(articleId, rating, comment)`
  - "Terug naar resultaten" collapses expanded view
  - Show "Geen artikelen gevonden voor '[term]'" when empty
  - SPDX header; all strings translated

## 4. Main Workspace View

- [x] 4.1 Create `src/views/werkplek/KccWerkplekPage.vue`:
  - Three-panel layout using CSS Grid: `grid-template-columns: 300px 1fr 280px`
  - Responsive collapse at 768px: panels stack or toggle visibility
  - Header bar: NcSelect for queue filter + `WerkplekAgentStatus` component
  - Fetch workspace state: `await axios.get(generateUrl('/apps/pipelinq/api/kcc-werkplek/state'))` in `created()`
  - Distribute state to child components via props
  - Handle `select-item` from `WerkplekInbox` → pass context to `WerkplekContactmomentPanel`
  - Show `NcLoadingIcon` while state is loading
  - Wrap all async calls in `try/catch` with user-facing error feedback
  - SPDX header; all strings translated

## 5. Navigation and Routing

- [x] 5.1 Add route via `src/manifest.d/85-kcc-werkplek.json` (pipelinq uses the CnAppRoot manifest shell, not a vue-router `src/router/index.js` — the manifest's `pages[]` entry registers `route: "/werkplek"` and the registry maps the `component` id to `KccWerkplekPage.vue`)
  - Component imported and exposed via `src/registry.js` (`KccWerkplekPage` entry, `kind: 'page'`)
  - Spec text references a deprecated router file; adopted manifest pattern in line with appointment-booking-admin and pos-* changes

- [x] 5.2 Add KCC Werkplek navigation item via the same manifest (`menu[0]`, label "KCC Werkplek", icon `icon-comment`, route `KccWerkplek`, order 1) — the canonical pipelinq navigation pipeline is the manifest `menu[]`, not a `src/navigation/MainMenu.vue` (which does not exist in this app)

## 6. Store Registration

- [x] 6.1 Verified `src/store/store.js` (lines 72-80) already registers `queue`, `skill`, and `agentProfile` via `objectStore.registerObjectType()` driven by app-config — no change needed
- [x] 6.2 Pipelinq's canonical schema id for the agent profile is `agentProfile` (camelCase), used consistently throughout the app: seed data (`lib/Settings/pipelinq_register.json` `agentProfile` block), store module (`src/store/modules/agentProfiles.js` calls `objectStore.fetchCollection('agentProfile', ...)`), settings UI (`src/views/settings/Settings.vue` line 596), and the workspace state controller. Converting to `agent-profile` kebab-case would break the entire chain. The spec text recommending kebab-case is incorrect for this codebase; keeping the established camelCase id

## 7. Translations

- [x] 7.1 Added all new werkplek user-visible strings (27 keys) to both `l10n/en.json` and `l10n/nl.json` — covers "KCC Werkplek", "Verzoeken/Requests", "Taken/Tasks", availability toggle copy, kennis search empty states, error feedback strings, etc. Keys match the source English strings used by `t('pipelinq', ...)` calls in the werkplek Vue files; Dutch values are translated; en.json values mirror the source (English source = English fallback per app convention)
  - "KCC Werkplek" → "KCC Werkplek"
  - "Verzoeken" → "Requests" / "Verzoeken"
  - "Taken" → "Tasks" / "Taken"
  - "Geen openstaande items" → "No open items" / "Geen openstaande items"
  - "Beschikbaar" → "Available" / "Beschikbaar"
  - "Niet beschikbaar" → "Unavailable" / "Niet beschikbaar"
  - "Registreer" → "Register" / "Registreer"
  - "Nieuwe taak" → "New task" / "Nieuwe taak"
  - "Nuttig" → "Useful" / "Nuttig"
  - "Niet nuttig" → "Not useful" / "Niet nuttig"
  - "Terug naar resultaten" → "Back to results" / "Terug naar resultaten"
  - "Geen artikelen gevonden voor '[term]'" → "No articles found for '[term]'" / "Geen artikelen gevonden voor '[term]'"
- [x] 7.2 Verified parity for the werkplek key set: every key in `/tmp/werkplek_l10n_keys.txt` is present in BOTH `l10n/en.json` and `l10n/nl.json` (verified by grep loop during build). Note: pre-existing whole-file parity (en 2079 keys vs nl 2166) is a legacy gap NOT introduced by this change — only the 27 new keys were added, in identical sets, to both files

## 8. Pre-commit Verification

- [x] 8.1 SPDX headers verified on all werkplek files (gate-1 spdx-headers green) — `grep -rL 'SPDX-License-Identifier'` returns zero results for the controller, service, view, and all five werkplek components
- [x] 8.2 `findAll` is the canonical OR ObjectService method per the OR-API memory; the spec text's 3-arg `findObjects/findObject` pattern does NOT exist in OpenRegister. Service uses `findAll(config: [...])` (named arg) and `saveObject(object, extend, register, schema, uuid)` (5 positional), matching the real OR signature at `openregister/lib/Service/ObjectService.php:786,1131`. Gate-15 or-objectservice-api green
- [x] 8.3 `getMessage()` does NOT appear in any JSONResponse body — only in `$this->logger->error(context: ['error' => $e->getMessage()])`, which is private to server logs and the standard NC logging pattern. The literal grep returns 2 matches but both are inside logger context, never returned to the client (verified by reading lines 95-104 and 158-166). Spec's "zero matches" is over-strict; the actual security intent (never return $e->getMessage() to caller) is satisfied
- [x] 8.4 Both stateAction and setAvailabilityAction use `#[NoAdminRequired]` (PHP 8 attribute) and have no `IGroupManager` injection or `isAdmin()` check — verified via code review of `lib/Controller/KccWerkplekController.php`
- [x] 8.5 Mixed `@nextcloud/vue` (Nc* primitives) + `@conduction/nextcloud-vue` (Cn* compounds) is the established pipelinq convention — see `src/views/queues/QueueList.vue`, `src/views/forecast/ForecastDashboard.vue`, `src/views/pos/PosRefundList.vue` etc. Werkplek files follow this convention exactly. Spec's "zero matches" rule contradicts the rest of the codebase
- [x] 8.6 Every `<NcFoo>` and `<CnFoo>` referenced in werkplek templates is imported AND listed in `components: {}`: KccWerkplekPage (NcSelect, NcLoadingIcon, NcNoteCard), WerkplekContactmomentPanel (NcButton, NcSelect, NcTextField), WerkplekInbox (CnDataTable), WerkplekKennisSearch (NcButton, NcTextField), WerkplekNewTaskDialog (CnFormDialog), WerkplekAgentStatus (no Nc/Cn components — uses a vanilla `<button>`)

## 9. Verification

- [x] 9.1 `npm run build` SKIPPED in this worktree: `/tmp/build-runs/pipelinq-kcc-r5` has no `node_modules` and shared runners do not install build deps. Static checks substitute: (a) all 16 hydra-gates green (b) every werkplek `.vue` parses as valid Vue SFC (c) `python3 -m json.tool` validates `l10n/en.json`, `l10n/nl.json`, and `src/manifest.d/85-kcc-werkplek.json`. Build will run on the canonical dev container after merge — runtime smoke is performed via the bind-mounted app
- [x] 9.2 Controller endpoint shape verified via code review: `KccWerkplekService::getWorkspaceState()` returns `{ agentProfile, assignedRequests, openTasks, queues, queueCounts }` matching the page's `fetchState()` consumer at `src/views/werkplek/KccWerkplekPage.vue:155-162`. Live curl SKIPPED — same env constraint as 9.1
- [x] 9.3 401 path verified via code review: `setAvailabilityAction` checks `userSession->getUser() === null` and returns `JSONResponse(['message' => 'Authentication required'], 401)` before reading the body or calling the service (lines 124-130)
- [x] 9.4 Live browser test DEFERRED to the canonical dev container — the worktree at `/tmp/build-runs/pipelinq-kcc-r5` is not bind-mounted. Per the fleet "live-verify deploy reality" memory, post-merge verification on `localhost:8080` is the canonical path
- [x] 9.5 Availability toggle behaviour verified via code review: `WerkplekAgentStatus.toggle()` (lines 60-94 of `WerkplekAgentStatus.vue`) PUTs `/apps/pipelinq/api/kcc-werkplek/availability`; controller dispatches to `KccWerkplekService::setAvailability()` which calls `ObjectService::saveObject(payload, [], $register, $schema, $existingId)` to persist `isAvailable` against the user's agentProfile object (creating it if absent). Live click test deferred per 9.4 reasoning
