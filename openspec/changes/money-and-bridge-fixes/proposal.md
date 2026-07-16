# pipelinq — money-and-bridge-fixes

## Why

An audit of pipelinq surfaced four defects of the **orphaned-capability** class:
code that is implemented, specced, and (mostly) tested, but that nothing ever
invokes — so the pipeline reports green while the feature does not run. Two of
the four sit directly on money paths.

Each finding was re-verified against `origin/development` (`4667efbbc`) before
any code was written; the audit was ~80% right and one finding's framing was
corrected by evidence (see `design.md`).

### 1. Deposits never charge (money path)

`PortalController::book()` returned `'paymentRedirect' => null` as a hardcoded
literal (`PortalController.php:206`). `AppointmentDepositService::createDepositSession()`
— a fully implemented openconnector payment orchestrator — had **zero callers**
from the portal. A customer booking a deposit-required Service got a booking in
`pending-deposit` and was never sent to checkout; the 15-minute
`AppointmentDepositTimeoutJob` then silently released the slot. The business
lost the booking *and* the deposit.

### 2. Gift cards can never be created (money path)

`GiftCardService::issueGiftCard()` mints a serial, bcrypt-hashes a PIN, and
writes the opening `issue` ledger entry — and had **zero callers**. The
`validate` / `redeem` / `activate` gift-card endpoints were all routed and live,
operating on a population that could never come into existence.

This is *not* an abandoned feature: `REQ-LOY-006 Gift Card Issuance` is
specified in the archived `2026-06-14-loyalty-program` change, the service is
complete, and three sibling endpoints depend on the issued population. The
creation path was simply never routed. **Verdict: wire, do not delete.**

### 3. ZGW write bridge dead

`DrcClient` (378 lines) and `BrcClient` (232 lines) — the zaak/document/besluit
write path — were **never constructed**: zero callers, no DI registration, no
route. Meanwhile `lib/Settings/register.d/80-zgw-api-bridge.json` advertised
them to the register as live infrastructure. ZGW writes are routed via the
openconnector ZGW connector instead (procest owns `BrcController` /
`AcController` / `ZtcController`). **Verdict: remove the dead bridge + the false
register.d claim.**

### 4. `TaskExpiryJob` phantom cron

`TaskExpiryJob` was registered in `appinfo/info.xml` under `<background-jobs>`,
but its `run()` only logged — it expired nothing. The deadline sweep that
actually works already lives in `ScheduledTaskService::processScheduledTasks()`,
driven by the registered `ScheduledTaskJob` (300s). The phantom job made the
canonical spec read as satisfied while duplicating a live path.

The real gap next to it: the `verlopen` transition dispatched **no** escalation
notification, even though `NotificationService::notifyTaskExpired()` existed
(itself another orphan). **Verdict: deregister the phantom, wire the real
escalation.**

## What Changes

- **Wire the deposit charge.** `PortalController::book()` resolves a real
  payment redirect via `AppointmentDepositService::createDepositSession()` for
  deposit-required Services (amount converted to integer cents), and returns
  `null` only when no charge is due or the PSP is genuinely unavailable.
- **Wire gift-card issuance.** New `LoyaltyController::issueGiftCard()` +
  `POST /api/loyalty/gift-card/issue`, admin-gated (issuance creates monetary
  value); the one-time plaintext PIN is returned once and never stored (PCI-DSS).
- **Remove the dead ZGW write bridge.** Delete `DrcClient` + `BrcClient`;
  correct the `80-zgw-api-bridge.json` description to state what is actually
  addressed (inbound status via `ZrcClient`, `NrcSubscriptionService`).
  `ZrcClient` / `ZtcClient` / `AcClient` are untouched and stay live.
- **Deregister the phantom job, wire real expiry escalation.** Delete
  `TaskExpiryJob` + its registration; `ScheduledTaskService` now calls
  `notifyTaskExpired()` on the `verlopen` transition.
- **Align the canonical `task-background-jobs` spec with the implementation
  that actually runs** (job name, 300s interval, 4-hour expiry cut,
  assignee-only escalation) instead of the deleted phantom.

## Impact

- Money: deposit bookings now reach checkout; gift cards can be issued.
- ~743 lines of dead code removed; one false register.d claim retracted.
- Suite: 1584 → 1586 unit tests, all green. No new PHPStan/PHPCS/PHPMD findings.

## Out of scope / follow-up

- **A live ZGW write bridge in pipelinq.** If pipelinq ever needs to *write*
  zaken/documenten/besluiten directly, it should consume the openconnector ZGW
  connector rather than resurrect these clients (ADR-022). Not needed today.
- **Approaching-deadline duplicate-reminder suppression** (`last reminder
  timestamp per task`) — the old spec asserted it; no implementation ever
  existed. The spec text now describes real behaviour; suppression is filed as
  follow-up rather than silently claimed.
