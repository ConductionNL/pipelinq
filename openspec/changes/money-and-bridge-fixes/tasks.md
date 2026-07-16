# Tasks — money-and-bridge-fixes

## 1. Deposits never charge (money path)

- [x] 1.1 Inject `AppointmentDepositService` into `PortalController`.
- [x] 1.2 `createBookingFromPortal()` returns the deposit context
      (`requiresDeposit`, `depositAmount`, `currency`, `serviceName`) alongside
      the booking id instead of a bare string.
- [x] 1.3 Add `resolvePaymentRedirect()` — opens a deposit session for
      deposit-required Services, amount as integer cents; returns null when no
      charge is due / PSP unavailable / session throws.
- [x] 1.4 Replace the hardcoded `'paymentRedirect' => null` literal.
- [x] 1.5 Test: deposit Service opens a session with 2000 cents and returns the
      hosted checkout URL (`testBookDepositServiceOpensPaymentSession`).
- [x] 1.6 Test: non-deposit Service never opens a session, returns null
      (`testBookNonDepositReturnsNullPaymentRedirect`).
- [x] 1.7 Baseline the resulting `ExcessiveParameterList` on the DI constructor
      (10 params) via `phpmd.baseline.xml`.

## 2. Gift cards can never be created (money path)

- [x] 2.1 Evidence check: is issuance abandoned or merely unrouted?
      → specified (REQ-LOY-006), service complete, 3 live sibling endpoints
      depend on it. **Wire, do not delete.**
- [x] 2.2 Add `LoyaltyController::issueGiftCard()`, admin-gated
      (`#[AuthorizedAdminSetting]`), validating `initialBalance > 0`.
- [x] 2.3 Register `POST /api/loyalty/gift-card/issue`.
- [x] 2.4 Bump the `AuthorizedAdminSetting` count 1 → 2 in `phpstan-baseline.neon`.
- [x] 2.5 Test: card created + one-time PIN returned
      (`testIssueGiftCardCreatesCardAndReturnsPin`).
- [x] 2.6 Test: non-positive balance rejected, service never called
      (`testIssueGiftCardRejectsNonPositiveBalance`).
- [x] 2.7 Test: unauthenticated rejected, service never called
      (`testIssueGiftCardRequiresAuthentication`).

## 3. ZGW write bridge dead

- [x] 3.1 Zero-caller proof for `DrcClient` + `BrcClient`.
- [x] 3.2 Supersession proof: procest owns `BrcController`/`AcController`/
      `ZtcController`; ocon provides connector transport (ADR-022).
- [x] 3.3 Delete `lib/Service/Zgw/DrcClient.php` (378 lines).
- [x] 3.4 Delete `lib/Service/Zgw/BrcClient.php` (232 lines).
- [x] 3.5 Correct the false register.d claim in `80-zgw-api-bridge.json` to
      describe the inbound-status path that is actually addressed.
- [x] 3.6 Scrub stale `DrcClient`/`BrcClient` prose from `ZgwApiClient` +
      `OptimisticLockException` docblocks.
- [x] 3.7 Confirm `ZrcClient`/`ZtcClient`/`AcClient`/`NrcSubscriptionService`
      remain live and untouched.
- [ ] 3.8 **Follow-up (not this change):** if pipelinq ever needs direct ZGW
      writes, consume the openconnector ZGW connector — do not resurrect these
      clients.

## 4. `TaskExpiryJob` phantom cron

- [x] 4.1 Confirm `run()` only logs and the live sweep is
      `ScheduledTaskJob` → `ScheduledTaskService::processScheduledTasks()`.
- [x] 4.2 Delete `lib/BackgroundJob/TaskExpiryJob.php` + its
      `TaskExpiryJobTest` (tested a job that did nothing).
- [x] 4.3 Deregister from `appinfo/info.xml` `<background-jobs>`.
- [x] 4.4 Drop its `phpmd.baseline.xml` + `phpstan-baseline.neon` entries.
- [x] 4.5 Wire `notifyTaskExpired()` into the `verlopen` transition; failures
      logged + swallowed so a bad notification never aborts the batch.
- [x] 4.6 Test: expired task transitions to `verlopen` **and** escalates to the
      assignee (`testExpiredTaskEscalatesToAssignee`).

## 5. Specs + quality

- [x] 5.1 Repoint the dangling `@spec` anchor on `notifyTaskExpired` from a
      non-existent change dir to the canonical spec (gate-51).
- [x] 5.2 Align canonical `task-background-jobs/spec.md` with the real
      implementation: job name, 300s interval, 4-hour expiry cut,
      assignee-only escalation, "no no-op job may be registered".
- [x] 5.3 Remove the spec's unimplemented duplicate-reminder-suppression claim;
      name it as follow-up instead of faking it.
- [ ] 5.4 **Follow-up (not this change):** implement per-task last-reminder
      timestamp suppression for approaching-deadline warnings.
- [x] 5.5 Suite green: 1584 (baseline) → 1586. PHPStan finding set identical to
      baseline; PHPCS 0 errors; PHPMD at parity.
