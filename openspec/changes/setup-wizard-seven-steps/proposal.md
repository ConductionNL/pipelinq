# Proposal: setup-wizard-seven-steps

## Why

Board PqInstellen draws the first-time setup as seven steps: Welkom, Voorbeeldgegevens, Valuta, Organisatie, Kassa, Integraties, Klaar. Live had five (no Kassa, no Integraties), and the organisation step had no "(optioneel)" labels, no "Deze stap overslaan" and no note that each field is saved as you continue. The board is canon (coordinator, 28 Oct).

## What changes

- Two config-fields steps after Organisatie: Kassa (receipt printer address and port, the receipts' sender) and Integraties (the address that takes the till's day totals in shillinq, the group that may send contracts to invoicing). Every field is one the app already reads.
- Organisatie, Kassa and Integraties are skippable, label their fields optional and close with "Per veld opgeslagen als je verdergaat …".

## Scope

`src/manifest.json` setup steps, l10n. Needs nc-vue `feat/setup-wizard-optional-skip` (schema 2.159.0 for `skippable` / `optionalLabels`); on the installed schema validate-manifest refuses the two keys.
