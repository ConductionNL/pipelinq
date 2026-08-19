# Tasks: leaf-integration-broadening

## 1. Register (linkedTypes)

- [ ] 1.1 Add `"talk"` to `client.linkedTypes` and `lead.linkedTypes`
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-talk-rooms-on-client-and-lead-detail`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `"talk"` appended to the `client` `linkedTypes` array (currently L89–96) and the `lead` `linkedTypes` array (currently L358–366)
    - `client` and `lead` schema `version` patch-bumped so the register import applies the change (version unchanged ⇒ import no-ops)
    - `contact` `linkedTypes` untouched (`email`, `calendar` only); no property, slug, or enum changes anywhere

## 2. Manifest widgets

- [ ] 2.1 Mount `lead-deck`, `lead-talk`, `lead-forms` on LeadDetail
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-deck-leaf-widget-on-leaddetail`, `specs/collaboration-leaves/spec.md#requirement-talk-rooms-on-client-and-lead-detail`, `specs/collaboration-leaves/spec.md#requirement-forms-leaf-widget-on-leaddetail`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - Three new `config.widgets` entries on the LeadDetail page: `lead-deck` (integrationId `deck`, title "Board", icon `BulletinBoard`), `lead-talk` (integrationId `talk`, title "Deal room", icon `Forum`), `lead-forms` (integrationId `forms`, title "Intake forms", icon `FormSelect`)
    - Layout rows appended below `lead-decisions` (gridY 19 + 4): `lead-talk` (0, 23, 8×4), `lead-deck` (8, 23, 4×4), `lead-forms` (0, 27, 12×4)
    - Body widgets only — `sidebar.tabs` stays audit-only
    - `npm run check:manifest` passes

- [ ] 2.2 Mount `client-talk` on ClientDetail
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-talk-rooms-on-client-and-lead-detail`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - `client-talk` widget (integrationId `talk`, title "Client room", icon `Forum`) + layout row appended below `client-notes` (currently gridY 23 + 4 ⇒ row at 0, 27, 12×4)
    - If `calendar-deepening` has landed first, the row is rebased below its `client-calendar` row instead (design R1)

- [ ] 2.3 Record the remaining deliberate exclusions in page `_note`s
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-declared-linkedtypes-are-mounted-or-recorded-as-deliberate-exclusions`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - LeadDetail/ClientDetail `_note`s name `flow` and `time-tracker` as excluded and `xwiki` as mounted app-level on `xwiki-knowledge`
    - TicketDetail `_note` names `deck` and `forms` as deferred exclusions
    - The stale "Generic Deck/Flow/Time/Knowledge/Forms leaves dropped" sentence in the LeadDetail `_note` is updated — deck and forms are now mounted as body widgets

## 3. Conformance test

- [ ] 3.1 Declared-vs-mounted conformance unit test
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-declared-linkedtypes-are-mounted-or-recorded-as-deliberate-exclusions`
  - **files**: `tests/Unit/LinkedTypesConformanceTest.php`
  - **acceptance_criteria**:
    - Parses `lib/Settings/pipelinq_register.json` + `lib/Settings/register.d/*.json` for `client`/`contact`/`lead`/`ticket` `linkedTypes` and `src/manifest.json` for `integrationId` widgets and `_note` exclusion markers
    - Fails naming schema + leaf type + page on an unmounted, unrecorded declaration; passes on the post-change state
    - Prove it can fail: temporarily remove a `_note` marker locally and confirm a red run before finalising

## 4. Verification

- [ ] 4.1 e2e coverage for the mounted widgets
  - **spec_ref**: `specs/collaboration-leaves/spec.md#requirement-deck-leaf-widget-on-leaddetail`, `specs/collaboration-leaves/spec.md#requirement-talk-rooms-on-client-and-lead-detail`, `specs/collaboration-leaves/spec.md#requirement-forms-leaf-widget-on-leaddetail`
  - **files**: `tests/e2e/`
  - **acceptance_criteria**:
    - Playwright asserts the "Board", "Deal room", "Intake forms" widgets render on LeadDetail and "Client room" on ClientDetail (widget presence + unavailable-state fallback when the NC app is absent on the CI instance)
    - Existing LeadDetail/ClientDetail e2e specs still pass (layout rows are appended, nothing moved)

- [ ] 4.2 Gates + live verification
  - **spec_ref**: all
  - **files**: —
  - **acceptance_criteria**:
    - `composer check:strict` green; hydra gates pass; `openspec validate leaf-integration-broadening --strict` clean
    - Live-verify on :8080 after register re-import: talk linkedTypes visible on the leaf's link surface for client + lead; all four widgets render
