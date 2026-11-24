---
description: Run the pipelinq quality suite (PHP strict gate, ESLint, Stylelint, l10n, manifest)
---

Run the quality suite from the repository root and report each failure with its file and line.

```bash
composer check:strict      # lint, phpcs, phpmd, psalm, phpstan, PHPUnit
npm run lint               # ESLint
npm run stylelint          # Stylelint
npm run test:l10n          # every t()/n() string has a catalogue entry
npm run check:manifest     # src/manifest.json is valid
```

Fix what the change touched; do not add baseline entries or suppressions to get green. Auto-fixers: `composer cs:fix`, `composer phpcs:fix`, `npm run lint-fix`.
