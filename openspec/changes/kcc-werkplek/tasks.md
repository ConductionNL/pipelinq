# Tasks: kcc-werkplek

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for overlap with `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService` — document findings; no overlap expected since workspace state aggregation is domain-specific and not provided by OpenRegister core
- [ ] 0.2 Verify `CallTimer.vue` already exists from `omnichannel-registratie` and is reused — do NOT create a new one
- [ ] 0.3 Verify `KennisbankService.submitFeedback()` already exists from `kennisbank` and is reused — do NOT duplicate feedback logic

## 1. Seed Data

- [ ] 1.1 Add 3 `queue` seed objects to `lib/Settings/pipelinq_register.json` per design.md if not already present (slugs: `queue-algemene-zaken`, `queue-vergunningen`, `queue-wmo-zorg`)
- [ ] 1.2 Add 3 `agentProfile` seed objects to `lib/Settings/pipelinq_register.json` (slugs: `agent-jan-de-vries`, `agent-fatima-el-amrani`, `agent-pieter-bakker`)
- [ ] 1.3 Add 3 `skill` seed objects to `lib/Settings/pipelinq_register.json` (slugs: `skill-vergunningen`, `skill-wmo-zorg`, `skill-algemene-dienstverlening`)
- [ ] 1.4 Verify all seed entries use `@self` envelope with `register: "pipelinq"`, correct schema name, and unique slug
- [ ] 1.5 Verify `importFromApp()` idempotency: re-importing must skip existing slugs (no duplicate check needed in code — this is a design-time verification)

## 2. Backend

- [ ] 2.1 Create `lib/Service/KccWerkplekService.php` with:
  - `getWorkspaceState(string $userId): array` — parallel `ObjectService::findObjects()` calls for requests (assignee=userId, status=new/in_progress), tasks (assigneeUserId=userId, status=open/in_behandeling), agentProfile (userId=userId), queue counts
  - `setAvailability(string $userId, bool $available): array` — find agentProfile by userId, update `isAvailable`, create new agentProfile if none exists
  - Add `@spec openspec/changes/kcc-werkplek/tasks.md#task-2` PHPDoc to file header and all public methods
- [ ] 2.2 Create `lib/Controller/KccWerkplekController.php` with:
  - `GET /api/kcc-werkplek/state` → `stateAction()` — calls `KccWerkplekService::getWorkspaceState($currentUser->getUID())`; returns JSONResponse; `@NoAdminRequired`
  - `PUT /api/kcc-werkplek/availability` → `setAvailabilityAction()` — reads `isAvailable` from request body, calls `KccWerkplekService::setAvailability()`; `@NoAdminRequired`
  - Catch all exceptions with `return new JSONResponse(['message' => 'Operation failed'], 500)` + `$this->logger->error()`; NEVER return `$e->getMessage()`
  - Add `@spec` PHPDoc to file header and all public methods
- [ ] 2.3 Add kcc-werkplek API routes to `appinfo/routes.php`:
  - `GET /api/kcc-werkplek/state`
  - `PUT /api/kcc-werkplek/availability`
  - Specific routes MUST be added BEFORE any wildcard `{slug}` routes

## 3. Frontend Components

- [ ] 3.1 Create `src/components/werkplek/WerkplekAgentStatus.vue`:
  - Toggle button: "Beschikbaar" (green) / "Niet beschikbaar" (grey) based on `isAvailable` prop
  - On toggle → `await axios.put(generateUrl('/apps/pipelinq/api/kcc-werkplek/availability'), { isAvailable })`
  - Wrap in `try/catch` with NcDialog error feedback on failure; revert toggle on error
  - SPDX header; all strings via `this.t('pipelinq', ...)`

- [ ] 3.2 Create `src/components/werkplek/WerkplekInbox.vue`:
  - Two sections: "Verzoeken" (requests) and "Taken" (tasks)
  - Use `CnDataTable` for each section; sort by priority descending
  - Highlight overdue tasks (deadline < now) with red text via NL Design token `var(--color-error)`
  - Emit `select-item` with the clicked item on row click
  - Show `CnEmptyState` with "Geen openstaande items" when both sections are empty
  - SPDX header; all strings translated

- [ ] 3.3 Create `src/components/werkplek/WerkplekContactmomentPanel.vue`:
  - Channel NcSelect (options: telefoon, email, balie, chat, post, social)
  - Show `CallTimer.vue` only when channel = telefoon; auto-populate duration on timer stop
  - Client search autocomplete: query `ObjectService.findObjects('request', 'client', { _search: term })`
  - Subject (required) + summary textarea + outcome NcSelect
  - "Registreer" button: validate subject + channel, call `objectStore.saveObject(contactmomentData)`; reset form on success
  - `agent` field MUST NOT accept frontend-supplied user ID — leave blank and let backend set it via `IUserSession`
  - "Nieuwe taak" button opens `CnFormDialog` (schema: task) pre-filled with `clientId` and `contactMomentSummary`
  - Every `await store.action()` wrapped in `try/catch` with user-facing feedback
  - SPDX header; all strings translated

- [ ] 3.4 Create `src/components/werkplek/WerkplekKennisSearch.vue`:
  - Debounced search field (300ms, min 2 chars) — use `setTimeout`/`clearTimeout` pattern
  - Query `createObjectStore('kennisartikel')` with `_search=term&status=gepubliceerd`
  - Results list: title + summary snippet (150 char truncation) + category badges
  - On result click: expand inline with full Markdown body (rendered via `marked`)
  - "Nuttig" / "Niet nuttig" buttons call `KennisbankService.submitFeedback(articleId, rating, comment)`
  - "Terug naar resultaten" collapses expanded view
  - Show "Geen artikelen gevonden voor '[term]'" when empty
  - SPDX header; all strings translated

## 4. Main Workspace View

- [ ] 4.1 Create `src/views/werkplek/KccWerkplekPage.vue`:
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

- [ ] 5.1 Add route to `src/router/index.js`:
  - `{ path: '/werkplek', name: 'KccWerkplek', component: KccWerkplekPage }`
  - Import component with lazy loading or direct import (match existing pattern in file)

- [ ] 5.2 Add KCC Werkplek as the **first** navigation item in `src/navigation/MainMenu.vue`:
  - Icon: `mdi-headset`
  - Label: `t('pipelinq', 'KCC Werkplek')` — Dutch translation in `l10n/nl.json`
  - Route: `{ name: 'KccWerkplek' }`

## 6. Store Registration

- [ ] 6.1 Verify in `src/store/store.js` that `queue`, `agentProfile`, and `skill` entity types are registered via `createObjectStore` — add registrations if missing
- [ ] 6.2 Entity type slugs MUST be kebab-case: `agent-profile`, `queue`, `skill` — grep for camelCase variants and fix all

## 7. Translations

- [ ] 7.1 Add all new user-visible strings to `l10n/en.json` and `l10n/nl.json`:
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
- [ ] 7.2 Verify both `l10n/en.json` and `l10n/nl.json` have exactly the same set of keys (zero gaps)

## 8. Pre-commit Verification

- [ ] 8.1 Run SPDX header check: `grep -rL 'SPDX-License-Identifier' src/views/werkplek/ src/components/werkplek/ lib/Controller/KccWerkplekController.php lib/Service/KccWerkplekService.php` — add headers to any file missing one
- [ ] 8.2 Run ObjectService call check: `grep -rn 'findObject\|saveObject\|findObjects' lib/Service/KccWerkplekService.php` — verify every call has 3 positional args
- [ ] 8.3 Run error response check: `grep -n 'getMessage()' lib/Controller/KccWerkplekController.php` — must return zero matches
- [ ] 8.4 Verify `PUT /api/kcc-werkplek/availability` controller method has no `IGroupManager::isAdmin()` check (this endpoint is for all agents, not admin-only) — confirm via code review
- [ ] 8.5 Run import source check: `grep -rn "from '@nextcloud/vue'" src/views/werkplek/ src/components/werkplek/` — must be zero matches (use `@conduction/nextcloud-vue`)
- [ ] 8.6 Verify every `<NcFoo>` and `<CnFoo>` in werkplek templates is imported AND listed in `components: {}`

## 9. Verification

- [ ] 9.1 Run `npm run build` — verify no errors or warnings
- [ ] 9.2 Call `GET /api/kcc-werkplek/state` with curl — verify response shape matches design
- [ ] 9.3 Call `PUT /api/kcc-werkplek/availability` without auth — verify HTTP 401
- [ ] 9.4 Test browser: navigate to `/werkplek`, verify three-panel layout renders, inbox loads, contactmoment form saves, knowledge search returns articles
- [ ] 9.5 Test availability toggle: click toggle, verify agentProfile.isAvailable changes in OpenRegister
