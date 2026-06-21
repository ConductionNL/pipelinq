# Tasks: pipelinq-activityservice-gate27

## 1. Diagnose

- [x] 1.1 Run gate-27 detector on `lib/Service/ActivityService.php` — confirm 8
  `phantom-foundation-call` findings on `publish()`.
- [x] 1.2 Classify each site (a/b/c). Result: 7× `$this->publish()` (own
  private helper) + 1× `$this->activityManager->publish()` (NC first-party
  `IManager::publish()`). Case (b) — legitimate first-party API, false positive.
- [x] 1.3 Confirm fleet-wide scope: same false positive on decidesk, shillinq,
  procest, softwarecatalog (`$activityManager->publish()`,
  `$publicationService->publish()`, `$oriPublicationService->publish()`).
- [x] 1.4 Confirm the gate's own acceptance bar is "zero-false-positive,
  pipelinq = 0 hits" and Rule E was added after the acceptance run.

## 2. Fix the gate (root cause)

- [x] 2.1 Add `_DENY_CALL_WITH_RECEIVER_RE` (captures receiver + method) and
  `_OR_RECEIVER_TOKENS` to `hydra/scripts/lib/check_phantom_cross_app_rpc.py`.
- [x] 2.2 Rewire Rule E's active denylist half to require an OR-handle receiver.
- [x] 2.3 Update the module docstring + skill text to document the receiver
  guard. (Skill text edit was permission-denied under `.claude/`; the docstring
  carries the rationale.)

## 3. Test the gate

- [x] 3.1 Add `hydra/scripts/lib/test_check_phantom_cross_app_rpc.py` — 15
  cases: OR-handle removed-method calls still flagged; NC Activity / own /
  app-local / ORI `publish()` not flagged; comment lines never flagged; Rules
  A–D still fire. All pass.

## 4. Verify

- [x] 4.1 Re-run detector on `ActivityService.php` → 0 findings.
- [x] 4.2 Re-run detector on the other apps' publish files → 0 findings.
- [x] 4.3 Synthetic real-incident fixture → 4 true positives still caught.
- [x] 4.4 `bash hydra/scripts/run-hydra-gates.sh` on pipelinq → ALL 27 GATES
  GREEN (gate-27 PASS, gate-6 PASS, gate-16 PASS).
- [x] 4.5 Unit: `tests/Unit/Service/ActivityServiceTest.php` 8/8 pass.
- [x] 4.6 Live (:8080): created a lead → new `oc_activity` row (`lead_created`),
  count 117 → 118. Activity publishing intact.

## 5. Ship

- [x] 5.1 `openspec validate pipelinq-activityservice-gate27 --strict`.
- [x] 5.2 Archive this change.
- [x] 5.3 Push pipelinq + hydra to origin/development.
