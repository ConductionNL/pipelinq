# Tasks: kcc-agent-panel

> A shared contact-centre panel fed by integriq's `kcc-cti-adapter`
> (ADR-032 `kind: code`). The proposal left implementation out of scope.
> Decision 231 (Ruben, 12 October 2026) started it: the call mirror is generic
> and lives here, the telephony adapter lives in integriq. Task 1 is built;
> the page waits for its board.

## Implementation tasks

### Task 1: Mirror a call event, resolve the caller once, keep it 30 days
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once`
- [x] The mirror: `lib/Service/Calls/CallMirror.php`, schema `mirroredCall` in `lib/Settings/register.d/71-mirrored-call.json` (register `pipelinq`; only the background service group may create, read and delete, nobody may update), slug in `SettingsLoadService`, `appinfo/info.xml` bumped so the import runs
  - One row per `callId` + `kind` + `sourceId`; a repeated delivery writes nothing. The mirror names no telephony platform: whatever delivers a call hands it plain fields.
  - The resolved party of the adapter is NOT stored, only its name: a party can carry a citizen service number.
- [x] The resolver: `lib/Service/Calls/CallerResolver.php` finds the clients and contacts with exactly that E.164 number and their open tickets, once, when the row is written
- [x] Delivery: `lib/Listener/CallEventListener.php`, registered in `Application::register()` for integriq's `OCA\Integriq\Event\CallEvent` (dispatched by `CtiEventIntake::dispatchFor()` on integriq development). In process, so there is no `POST /api/kcc/call-events`, no secret and no subscription (design D3, amended)
- [x] Retention: `lib/BackgroundJob/CallMirrorRetentionJob.php`, listed in `appinfo/info.xml`, removes rows 30 days after they were written
- [x] Tests: `tests/Unit/Service/Calls/CallMirrorTest.php` (the payload against the composed schema with two controls, a repeat, a store that ignores filters, another country's number with the same last nine digits, retention), `tests/Unit/Listener/CallEventListenerTest.php` (from `Application::register()` to the mirror, with integriq's constructor), `tests/Unit/BackgroundJob/CallMirrorRetentionJobTest.php` (from `info.xml` to the purge)
- [x] Identity (12 October, review of #2458): every write and delete of the mirror runs as the background service account with access checks on, never with `_rbac: false`
  - `lib/Service/BackgroundServiceAccount.php` on the existing `ServiceAccount` base (the same model as the portal and messaging accounts), group `pipelinq-background-service`, granted on `mirroredCall` only
  - An admin picks it: `lib/Controller/BackgroundServiceAccountController.php` (`GET`/`PUT /api/background/service-account`), `src/views/settings/BackgroundServiceAccountSettings.vue` in the admin settings, `lib/SetupCheck/BackgroundServiceAccountCheck.php`, `lib/Repair/CreateBackgroundServiceGroup.php` on upgrade and install
  - Without a usable account the mirror reads, writes and removes nothing and logs a warning
  - Tests: `BackgroundServiceAccountTest`, `BackgroundServiceAccountControllerTest`, `BackgroundServiceAccountCheckTest`, `CreateBackgroundServiceGroupTest`, and in `CallMirrorTest` who acts with which access flags on every call to OpenRegister
- [x] The event is not trusted (12 October): `lib/Service/Calls/MirroredCallRow.php` refuses a key that cannot be a key and brings every other field inside the schema's bounds; the schema bounds `openCases` and `inPipelinq`. `CallMirrorTest` runs twelve hostile events against the composed schema and pins the class's bounds to the schema's
- [x] The stand-in for integriq's event: `tests/Stubs/Integriq/Event/CallEvent.php`, declared only when the real class is absent, with `tests/Unit/Support/IntegriqCallEventStubDriftTest.php` comparing it with integriq's own file
- [ ] 1a "Elsewhere" matches: a row of another app's schema that implements `ns#Requester` or `ns#Customer` and carries the caller's identifying number (not built: it needs `SemanticHandoffService::implementerLocation()`, which is on pipelinq #2445 and not on development, and a rule for which field of such a schema holds the number)
- [x] 1b The incoming-call card also shows a mirrored call: `lib/Service/Calls/MirroredCallReader.php` (the agent's newest open call, read as the background service account, only rows that name the agent, number and times only), `IncomingCallFinder` picks the newer of pipelinq's own call and the mirrored one and works without a platform in pipelinq, `IncomingCallCard.vue` writes no ticket for a mirrored call. Tests: `tests/Unit/Service/Calls/MirroredCallReaderTest.php`, `IncomingCallFinderTest` (+2), `tests/vitest/incomingCallCard.spec.js` (+1)
- [ ] 1c (live pass, decision 139) With integriq installed and a CTI source of the log binding: post a `ringing` event, see one `mirroredCall` row with `occ` or the admin object API; post it again, still one row

### Task 2: The agent panel page (UI waits for board PqGesprekspaneel, decision 162)
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-panel-shows-the-caller-and-their-open-items`
- **files**: `src/manifest.json` (page `KccAgentPanel`), a component for the
  live call/identity/items view, `tests/vitest/kccAgentPanel.spec.js`
- **acceptance_criteria**:
  - The panel subscribes to the `pipelinq-mirroredCall` collection and reflects
    a new call without a manual refresh
  - An unmatched caller renders "Unknown caller" and no items, not a hidden
    or errored section
  - Each "Elsewhere" match links to its source app's own page; no shared
    component or iframe is introduced
  - `?callId=` focuses one call; its absence shows the newest active one
- [ ] Implement
- [ ] Test

### Task 3: Cross-app link convention documented
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-another-app-links-in-with-a-plain-url`
- **files**: `docs/` (pipelinq's own integration doc for this panel),
  `tests/vitest/kccAgentPanel.spec.js`
- **acceptance_criteria**:
  - The documented URL and its `?callId=` parameter are covered by a test
    that constructs the link the way a consuming app would
  - `npm test` and `composer check:strict` pass
- [ ] Implement
- [ ] Test
