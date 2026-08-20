---
kind: code
---

## Why

The evergreen working-style rule "i18n keys = ENGLISH source — never Dutch as i18n key" is
violated across at least 19 files (~70+ call sites) in pipelinq. Confirmed, verifiable examples:

- `src/components/pos/ZReportBookkeepingSection.vue:27,30,37,51` —
  `t('pipelinq', 'Boekhoudkundige status')`, `t('pipelinq', 'Inboekstatus shillinq')`,
  `t('pipelinq', 'Shillinq journaalpost-id')`, `t('pipelinq', 'Opnieuw raisen bij shillinq')`.
- `src/components/ProjectWbsTree.vue:21,23,32` — `t('pipelinq', 'Er zijn nog geen fasen voor dit
  project.')`, `t('pipelinq', 'Fase toevoegen')`, `t('pipelinq', '(naamloze fase)')`.
- `src/views/projects/ProjectDetail.vue:31,32,57,214,227,488,563` — `t('pipelinq', 'Opslaan')`,
  `t('pipelinq', 'Annuleren')`, `t('pipelinq', 'Verwijderen')`, `t('pipelinq', 'Fase
  toevoegen')`, `t('pipelinq', '[Verwijderde client]')`, `t('pipelinq', 'Weet je zeker dat je dit
  project wilt verwijderen?')` (~22 Dutch-literal calls in this file alone).
- `src/views/kassakoppeling/KassakoppelingAuditDetail.vue:32,69,100,249,258,261,263,298,305` —
  `t('pipelinq', 'Verificatiestatus')`, `t('pipelinq', 'Nog niet geëxporteerd')`,
  `t('pipelinq', 'Verificatie nog niet uitgevoerd')`, and several full Dutch sentences describing
  the HMAC verification outcome.
- `src/views/kassakoppeling/KassakoppelingAuditList.vue:20,67,76,117,301,309` — including a full
  Dutch help sentence and two Dutch placeholder examples (`'bijv. REG-001'`).
- Also confirmed in: `src/components/BrpContactPanel.vue`, `src/components/pos/
  CashShiftActionsSection.vue`, `src/components/pos/TaxBreakdownCard.vue`,
  `src/dialogs/BelastingdienstExportDialog.vue`, `src/modals/BrpDoelbindingModal.vue`,
  `src/modals/CashShiftCountDialog.vue`, `src/modals/CashShiftDropDialog.vue`,
  `src/modals/CashShiftOpenDialog.vue`, `src/modals/CashShiftRejectDialog.vue`,
  `src/views/admin/BrpMonitor.vue`, `src/views/pos/CashShiftList.vue`,
  `src/views/pos/PosRefundForm.vue`, `src/views/projects/ProjectActivityList.vue`,
  `src/views/settings/PaymentSettingsForm.vue`.

**Independently confirmed by the shipped locale files.** `l10n/en.json` maps these Dutch strings
*as keys* to hand-written English values (e.g. `"Opnieuw raisen bij shillinq": "Re-raise in
shillinq"`, `"Boekhoudkundige status": "Bookkeeping status"`) — i.e. translation tooling had to
translate the *key* into English, backwards from the intended flow. A `l10n/en.json` vs.
`l10n/nl.json` key diff shows **19 keys present in `en.json` with no corresponding entry in
`nl.json`** (`de`/`es`/`fr`/`it` are similarly short), meaning any non-Dutch-speaking user
(including English admins/agents, per the app's own English-source i18n convention) sees raw
Dutch prose with no fallback translation for these strings today — the opposite of the intended
behavior, since Dutch is supposed to be a *translation*, not the key.

This is purely a source-language hygiene issue, not a missing-translation issue (the Dutch text
already renders correctly for `nl` users because the key IS the Dutch text); the risk is for every
other locale.

## What Changes

- For every `t('pipelinq', '<dutch text>')` call site listed above (and any other instance found
  by the repo-wide sweep in tasks.md), replace the Dutch literal key with a concise English source
  string that reads naturally as a translation key, per the existing convention used everywhere
  else in this codebase (e.g. `t('pipelinq', 'Bookkeeping status')`,
  `t('pipelinq', 'Re-raise in shillinq')`, `t('pipelinq', 'No phases yet for this project.')`).
- Update `l10n/en.json` to map the **new English key** to itself (or a slightly polished English
  value, matching house convention elsewhere in `en.json`), and update `l10n/nl.json` to map the
  new English key to the **original Dutch text** that used to be the key — so Dutch users see
  the exact same UI text as before, and every other locale now gets a real English fallback
  instead of raw Dutch.
- No visible change for `nl` users (same Dutch text renders). Visible improvement for `en`/`de`/
  `es`/`fr`/`it` users, who currently see untranslated Dutch strings for these ~70 call sites and
  will now see a real (English-source, translated-or-fallback) string.
- Not BREAKING: this only changes the internal i18n *key* string and locale-file key names, not
  any prop, event, or route contract. `l10n/de.json`/`es.json`/`fr.json`/`it.json` may gain
  fallback-to-English entries for the renamed keys if a translation is not readily available;
  translation improvement for those four locales is out of scope for this change.

## Impact

- 19 `.vue` files listed above (or discovered by the tasks.md sweep).
- `l10n/en.json`, `l10n/nl.json` (key renames); `l10n/de.json`, `l10n/es.json`, `l10n/fr.json`,
  `l10n/it.json` (add English-source keys, falling back to English text where no translation is
  available).
