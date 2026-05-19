# Tasks: pipelinq PHPCS burn-down + inventory

ADR-032 cap respected (≤20 unchecked tasks).

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs` and capture current baseline error count
      (target: starting from 3 exclude-patterns in phpcs.xml)
- [ ] Run `composer phpmd` for the first time as a unified gate
      and capture violation count + categories
- [ ] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories
- [ ] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger)
- [ ] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>`
entry, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude
- [ ] Excluded file 2 — fix sniffs + drop exclude
- [ ] Excluded file 3 — fix sniffs + drop exclude
- [ ] Once all excludes are gone, drop the legacy-debt block from
      phpcs.xml entirely
