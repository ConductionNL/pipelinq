# Proposal: pos-receipt-transaction-card

## Why

pl4 on board PqKassabon. The Transactie card read its fields in schema order (Kassamedewerker, Klant, Prijsweergave,
then Kenmerk, Status, Kassa) and left out Aangemaakt and Afgerekend, because the schema marks `confirmedAt` and
`settledAt` `visible: false`. The board reads Kenmerk, Status, Kassamedewerker / Kassa, Prijsweergave, Klant /
Aangemaakt, Afgerekend. The Totalen list drew each label right-aligned in a 130px padded column with the rows far apart:
Nextcloud's global `dt` rule. The receipt card's Bon printen and Bon mailen were white outlined buttons where the board
draws light-blue pills.

## What changes

- The transaction card orders its fields as the board does (`order` per field) and brings back `confirmedAt` and
  `settledAt` for this card only (`hidden: false`, not editable). The schema is unchanged.
- The Totalen summary list undoes Nextcloud's global `dt`/`dd` padding, width and alignment for its own rows.
- The receipt card's two actions carry `variant: "tonal"`.
