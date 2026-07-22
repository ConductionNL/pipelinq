# money-and-bridge-fixes (plq#401) — shipped 2026-07-16

PRs: **#405** (apply, merged `e9b00f18`) · **#406** (archive, merged).
Branch `wip/money-and-bridge-fixes` resumed from preserved WIP `d41acc822`.

## Prior agent vs this session

**Prior agent had built** (18 files, untested): deposit redirect wiring in
`PortalController` (+`resolvePaymentRedirect`, ctor injection, deposit-context
return shape); `LoyaltyController::issueGiftCard` + route; `notifyTaskExpired`
dispatch in `ScheduledTaskService`; deletion of `TaskExpiryJob`, `DrcClient`,
`BrcClient`; register.d/docblock prose corrections; baseline hygiene; 4 new
tests. No openspec change dir existed.

**This session finished**: merged `origin/development` (`4667efbbc`, one
modify/delete conflict on `TaskExpiryJob` → kept deletion); verified all four
findings + both deletions against HEAD; established the real baseline; fixed
two genuine defects in the preserved work; authored the missing openspec change
+ spec deltas; synced canonical specs; shipped.

### Defects found in the preserved work
1. **Dangling `@spec` anchor** — `notifyTaskExpired` pointed at
   `openspec/changes/task-background-jobs/tasks.md#task-1`: a change dir that
   does not exist, violating the canonical-spec rule (gate-46). Repointed to
   `openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications`.
2. **New PHPMD violation** — the deposit injection pushed `PortalController::__construct`
   to 10 params (`ExcessiveParameterList`, first in the repo). Baselined via the
   repo's own `phpmd.baseline.xml` rather than degrading to a service-locator lookup.

## Per-finding verdict

| # | Finding | Verdict |
|---|---|---|
| 1 | Deposits never charge (`paymentRedirect` hardcoded null, `PortalController.php:206`) | **WIRED** |
| 2 | Gift cards can never be created (`issueGiftCard` zero callers) | **WIRED** (not deleted — evidence below) |
| 3 | ZGW write bridge dead + false register.d claim | **DELETED** (610 lines) |
| 4 | `TaskExpiryJob` phantom cron | **DEREGISTERED** (133 lines) + real escalation wired |

- **#2 wire-not-delete evidence**: `REQ-LOY-006 Gift Card Issuance` specified
  (`archive/2026-06-14-loyalty-program/specs.md:248`); service complete (serial
  retry, bcrypt PIN, opening ledger entry); `lookupGiftCard`/`redeemGiftCard`/
  `activateGiftCard` all routed+live on a population that could not exist.
  Deleting would have stranded three live endpoints. Only a route was missing.
- **#3 deletion proof**: zero refs to `DrcClient`/`BrcClient` anywhere in
  `lib/`/`appinfo/`/canonical specs. Superseder: procest owns `BrcController`/
  `AcController`/`ZtcController`; ocon supplies connector transport (ADR-022).
  `ZrcClient`/`ZtcClient`/`AcClient`/`NrcSubscriptionService` stay live
  (inbound status via `NrcNotificationListener`).
- **#4**: giving the phantom a body would have raced the live
  `ScheduledTaskJob` → `processScheduledTasks()` (300s) sweep on the same
  objects. Deleted instead; the real gap (no escalation on `verlopen`, despite
  `NotificationService::notifyTaskExpired()` existing as another orphan) wired.

## Spec honesty

Old `task-background-jobs` spec asserted a 15-min interval (real: 300s) and
duplicate-reminder suppression via a per-task last-reminder timestamp (real:
**nothing** — grep-confirmed absent). Spec rewritten to real behaviour;
suppression named as follow-up rather than faked. Canonical `appointment-booking`
REQ-APT-010 gained the redirect contract it always implied.

## Verification (php:8.3-cli, fresh composer install)

- Baseline pristine `origin/development`: **1584 tests / 4916 assertions — OK**
- Branch: **1586 tests / 4933 assertions — OK** (net +2: −TaskExpiryJobTest, +6 new)
- PHPStan: finding sets **identical** to baseline (diffed by name, not exit code)
- PHPCS: 0 errors · PHPMD: parity after baseline entry
- Hydra gates: all PASS except 30/31/32/46 — **identical on pristine baseline**
  (27/12/8); gate-46 improves **2017 → 2014**

Money/expiry proving tests (all green):
`testBookDepositServiceOpensPaymentSession` (asserts `createDepositSession`
invoked once with **2000 cents**, hosted URL returned) ·
`testBookNonDepositReturnsNullPaymentRedirect` (session never opened) ·
`testIssueGiftCardCreatesCardAndReturnsPin` · `testIssueGiftCardRejectsNonPositiveBalance`
+ `testIssueGiftCardRequiresAuthentication` (service `expects(never())`) ·
`testExpiredTaskEscalatesToAssignee` (asserts `verlopen` **and** notify).

No redirect or charge was fabricated to make a test pass.

## Follow-ups (named, not blocked)

- Direct ZGW writes, if ever needed → consume the ocon ZGW connector; do not
  resurrect `DrcClient`/`BrcClient`.
- Per-task last-reminder-timestamp suppression for approaching-deadline warnings.
- `composer check:strict` swallows exit codes via `|| echo` for phpmd/psalm/
  phpstan (same "check:strict is theatre" defect fixed in hrmq#80) — pipelinq is
  still exposed; worth a separate change.
