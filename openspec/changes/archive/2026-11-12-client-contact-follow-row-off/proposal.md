# Proposal: client-contact-follow-row-off

## Why

PQ-NEXT baseline (3 Nov) on boards PqKlant, PqOrganisatie and PqContactpersoon: live draws a "Volgen 0" follow row
under the page header (header 113 vs 79), so the warning card, the stat tiles, the tabs and every card below sit 34px
lower than drawn. None of the three boards draws a follow row, and neither does DqContact or DqOrganisatie, the
dossiq boards these pages share their cards with (dossiq wins).

## What changes

- The simple profile turns the follow row off on ClientDetail (persons and organisations) and ContactDetail
  (`follow: false`), as it does on the ticket, task, Z report and social post pages.
- The full profile keeps the follow row on those pages.

## Not in this change

- The cards under the tabs: example-set rows linked to Sanne de Vries by other boards (8 cases, 5 open tickets
  against the board's 2 and 1), the Identiteit and Inwoner fields, the hermiq summary card. They are measured again
  once the header is as drawn.
