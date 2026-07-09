## 1. Repo-wide inventory

- [ ] 1.1 Run a repo-wide sweep for `t('pipelinq', '<dutch>')` / `n('pipelinq', ...)` call sites
      whose literal key is Dutch prose (heuristic: contains `niet`, `geen`, `kon niet`,
      `verwijder(en)`, `toevoegen`, `opslaan`, `annuleren`, `gevonden`, `geladen`, `wilt`,
      `weet je`, `geconfigureerd`, `fase`, or any full Dutch sentence) across `src/**/*.vue` and
      `src/**/*.js`. Confirmed starting set (verify against HEAD, this list may be incomplete):
      `src/components/BrpContactPanel.vue`, `src/components/ProjectWbsTree.vue`,
      `src/components/pos/CashShiftActionsSection.vue`, `src/components/pos/TaxBreakdownCard.vue`,
      `src/components/pos/ZReportBookkeepingSection.vue`,
      `src/dialogs/BelastingdienstExportDialog.vue`, `src/modals/BrpDoelbindingModal.vue`,
      `src/modals/CashShiftCountDialog.vue`, `src/modals/CashShiftDropDialog.vue`,
      `src/modals/CashShiftOpenDialog.vue`, `src/modals/CashShiftRejectDialog.vue`,
      `src/views/admin/BrpMonitor.vue`, `src/views/kassakoppeling/KassakoppelingAuditDetail.vue`,
      `src/views/kassakoppeling/KassakoppelingAuditList.vue`, `src/views/pos/CashShiftList.vue`,
      `src/views/pos/PosRefundForm.vue`, `src/views/projects/ProjectActivityList.vue`,
      `src/views/projects/ProjectDetail.vue`, `src/views/settings/PaymentSettingsForm.vue`.
- [ ] 1.2 For each call site, note the exact Dutch key string, the file:line, and draft the
      replacement English key (concise, matches the tone of existing English keys in the same
      file/component).

## 2. Replace Dutch literal keys with English source strings

- [ ] 2.1 Kassakoppeling audit surfaces (`KassakoppelingAuditList.vue`,
      `KassakoppelingAuditDetail.vue`) — replace all Dutch-literal `t()` keys, including the two
      Dutch placeholder examples (`'bijv. REG-001'` → `'e.g. REG-001'`) and the multi-sentence
      verification-outcome strings, with English keys.
- [ ] 2.2 Project surfaces (`ProjectDetail.vue`, `ProjectActivityList.vue`, `ProjectWbsTree.vue`)
      — replace all Dutch-literal `t()` keys (confirm/cancel/delete labels, the empty-state
      sentence, `'(naamloze fase)'`, the delete-confirmation sentence, etc.).
- [ ] 2.3 POS / cash-shift / BRP / payment surfaces (`ZReportBookkeepingSection.vue`,
      `CashShiftActionsSection.vue`, `TaxBreakdownCard.vue`, `CashShiftList.vue`,
      `PosRefundForm.vue`, `CashShiftCountDialog.vue`, `CashShiftDropDialog.vue`,
      `CashShiftOpenDialog.vue`, `CashShiftRejectDialog.vue`, `BrpContactPanel.vue`,
      `BrpDoelbindingModal.vue`, `BrpMonitor.vue`, `BelastingdienstExportDialog.vue`,
      `PaymentSettingsForm.vue`) — replace all Dutch-literal `t()` keys.
- [ ] 2.4 Re-run the sweep from task 1.1 against the updated files and confirm zero remaining
      Dutch-literal `t()`/`n()` keys.

## 3. Reconcile locale files

- [ ] 3.1 In `l10n/en.json`, rename each affected key from the old Dutch string to the new English
      key, keeping the existing English value (or the new key itself if it already reads as
      correct English).
- [ ] 3.2 In `l10n/nl.json`, rename each affected key to the new English key and set its value to
      the **original Dutch text** that used to be the key, so Dutch users see byte-identical UI
      text before/after this change.
- [ ] 3.3 In `l10n/de.json`, `l10n/es.json`, `l10n/fr.json`, `l10n/it.json`, add the new English
      keys. Where no existing translation is available, fall back to the English string as the
      value (matching this repo's existing convention for untranslated keys in these files —
      confirm the convention by inspecting a few existing entries before assuming).
- [ ] 3.4 Run whatever locale-validation step this repo uses (check `package.json` scripts for an
      `l10n`/`translation` check; pipelinq has no dedicated l10n tooling like opencatalogi's
      `check:l10n` — hand-edit the JSON files directly per CLAUDE.md) and confirm all 6 locale
      files remain valid JSON with no duplicate keys.

## 4. Verify

- [ ] 4.1 Run `npm run build` and confirm no build errors.
- [ ] 4.2 Spot-check in the running app with the Nextcloud UI language set to Dutch: the affected
      screens (Kassakoppeling audit, Project detail, Z-report bookkeeping, cash-shift dialogs,
      BRP panel) render byte-identical Dutch text to before this change.
- [ ] 4.3 Spot-check with the UI language set to English: the same screens now show English text
      instead of raw Dutch.
- [ ] 4.4 Run `npm run lint` and fix any pre-existing lint issues surfaced in the touched files per
      CLAUDE.md.
