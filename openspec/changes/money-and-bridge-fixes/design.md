# Design — money-and-bridge-fixes

## Verification of the audit before implementation

The audit was ~80% right. Every finding was re-checked against
`origin/development` (`4667efbbc`) with file:line evidence before any code was
written, because supersession-checks had been killing wrongly-filed findings all
cycle.

| # | Audit claim | Verified? | Verdict |
|---|---|---|---|
| 1 | `paymentRedirect` hardcoded null at `PortalController.php:206` | ✅ exact | **wired** |
| 2 | Gift-card creation path unreachable | ✅ zero callers of `GiftCardService::issueGiftCard` | **wired** (not deleted — see below) |
| 3 | ZGW write bridge dead, register.d claims it live | ✅ zero callers of `DrcClient`/`BrcClient`; register.d text false | **deleted** |
| 4 | `TaskExpiryJob::run()` only logs | ✅ registered in `info.xml`, body logs only | **deregistered** + real escalation wired |

### Finding 2: why wire rather than delete

The brief allowed deleting an abandoned feature. The evidence says it is not
abandoned:

- `REQ-LOY-006 Gift Card Issuance` is specified
  (`openspec/changes/archive/2026-06-14-loyalty-program/specs.md:248`).
- `GiftCardService::issueGiftCard()` is complete: unique-serial retry, bcrypt
  PIN (cost 10), opening `issue` ledger entry, `issued` status, expiry.
- `lookupGiftCard` / `redeemGiftCard` / `activateGiftCard` are **routed and
  live** and operate on issued cards.

Deleting issuance would strand three live endpoints on an empty population. The
only missing link was a route. Wiring is the smaller, truer change.

### Finding 3: supersession proof

`DrcClient` / `BrcClient` are referenced by **nothing** in `lib/`, `appinfo/`,
or any canonical spec (`grep -rn 'DrcClient\|BrcClient'` → only their own files
+ prose in `ZgwApiClient` / `OptimisticLockException` docblocks, both corrected).
ZGW writes are owned elsewhere: procest exposes `BrcController`, `AcController`,
`ZtcController`, and openconnector provides the generic connector transport —
consistent with ADR-022 (apps consume OR/ocon abstractions rather than
hand-rolling per-app bridges).

What survives and is genuinely live: `ZgwApiClient` (transport + JWT),
`ZrcClient` (inbound status via `NrcNotificationListener`),
`ZtcClient`, `AcClient`, `NrcSubscriptionService`. The register.d description
was rewritten to describe exactly this, instead of advertising a write bridge
that does not exist.

## Deposit redirect: failure semantics

`resolvePaymentRedirect()` returns `null` in three distinct cases, and this is
deliberate:

1. **No charge due** — Service has no deposit, or amount ≤ 0.
2. **PSP unavailable** — `AppointmentDepositService` degrades to an empty
   `sessionUrl` when openconnector's PaymentService is absent/unconfigured. We
   do **not** invent a URL. The existing 15-minute
   `AppointmentDepositTimeoutJob` releases the slot, and the portal surfaces its
   static fallback.
3. **Session creation threw** — logged at warning, booking preserved, null
   returned. A failed PSP handshake must not lose an otherwise valid booking.

Amounts cross the boundary as **integer cents** (`(int) round($amount * 100)`),
never floats — no binary-fraction drift on a money path.

## Gift-card issuance: authorization

`issueGiftCard` is `#[AuthorizedAdminSetting(Application::APP_ID)]`, unlike its
`#[NoAdminRequired]` POS siblings. Issuance *creates monetary value* out of
nothing; validate/redeem/activate only move value that already exists. The
plaintext PIN is returned exactly once in the response and never stored or
logged (only the bcrypt hash is persisted) — PCI-DSS.

## Task expiry: why the phantom job went rather than gained a body

Two candidates existed for "make expiry real":

- Implement `TaskExpiryJob::run()` — would **duplicate** the sweep already
  performed by the live `ScheduledTaskJob` → `ScheduledTaskService`, racing it
  on the same objects.
- Delete the phantom, and fix what the live path was missing.

The live path already expired tasks correctly (`verlopen` past a 4-hour cut). It
was missing only the escalation dispatch. So: phantom deleted, dispatch wired.
The canonical spec was rewritten to describe the path that runs (`ScheduledTaskJob`,
300s, 4-hour cut, assignee-only escalation) rather than the one that never did.

`NotificationService::notifyTaskExpired()` already existed with zero callers —
the same orphaned-capability class as the other three findings.

## Spec honesty

The old `task-background-jobs` spec asserted two things the code never did:
15-minute interval (real: 300s) and duplicate-reminder suppression via a
per-task last-reminder timestamp (real: nothing). Rather than leave the spec
lying or fake an implementation to match it, the spec now states real
behaviour and suppression is named as follow-up in `proposal.md`.
