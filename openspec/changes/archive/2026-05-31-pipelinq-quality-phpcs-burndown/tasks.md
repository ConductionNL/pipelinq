# Tasks: pipelinq PHPCS burn-down + inventory

ADR-032 cap respected (≤20 unchecked tasks).

## Phase 1 — Inventory + planning

- [x] Run `composer phpcs` and capture current baseline error count
      (target: starting from 3 exclude-patterns in phpcs.xml)
      → **0 errors, 174 warnings** (all `CustomSniffs.Commenting.SpecTag`:
      94 method-spec + 80 class-spec, intentionally `<type>warning</type>`
      per ADR-003, non-blocking). See `inventory.md`.
- [x] Run `composer phpmd` for the first time as a unified gate
      and capture violation count + categories
      → **36 violations above the `phpmd.baseline.xml`** (raw exit 0 — all
      currently baselined). Categories: CyclomaticComplexity, NPathComplexity,
      ExcessiveClassComplexity, CouplingBetweenObjects, UnusedPrivateMethod,
      ShortVariable, ExcessiveMethodLength. Feeds the PHPMD slice. See
      `inventory.md`.
- [x] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories
      → **0 errors** (`[OK] No errors`, level per `phpstan.neon`). See
      `inventory.md`.
- [x] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger)
      → PHPCS = **fix-outright** (0 errors already; warnings are advisory).
      PHPStan = already clean. PHPMD = 36 baselined; handled by the dedicated
      PHPMD slice (not this PHPCS slice).
- [x] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work
      → `.github/workflows/code-quality.yml` calls the shared reusable
      workflow `ConductionNL/.github/.github/workflows/quality.yml@main`
      (the canonical gate runner: lint + phpcs + phpmd + psalm + phpstan)
      on `pull_request` to main/beta/development. Confirmed.

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>`
entry, verify gate stays green.

- [x] Excluded file 1 — fix sniffs + drop exclude
      → **N/A: already cleared.** The per-file/legacy-debt `<exclude-pattern>`
      entries the parent proposal anticipated were already removed on
      `origin/development` by commits `de4fd36` ("clear phpcs errors …") and
      `b091e1e` ("sync canonical root configs"). The only remaining
      exclude-patterns in `phpcs.xml` are legitimate infrastructure excludes
      (`*/vendor/*`, `*/vendor-bin/*`, `*/node_modules/*`,
      `composer-setup.php`, `lib/Resources/template/*`) — not legacy debt.
- [x] Excluded file 2 — fix sniffs + drop exclude
      → N/A: already cleared (see file 1).
- [x] Excluded file 3 — fix sniffs + drop exclude
      → N/A: already cleared (see file 1).
- [x] Once all excludes are gone, drop the legacy-debt block from
      phpcs.xml entirely
      → **N/A: no legacy-debt block exists.** Verified via
      `git log -S "legacy-debt" -- phpcs.xml` (no matches in history). The
      PHPCS gate currently exits 0 (`./vendor/bin/phpcs --standard=phpcs.xml`),
      so the burn-down goal (zero PHPCS errors, no legacy per-file excludes) is
      already satisfied.
