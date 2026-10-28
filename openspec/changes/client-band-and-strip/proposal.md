# Proposal: client-band-and-strip

## Why

COMPDIFF pl3 (28 Oct, after pq-b5e reached :8080) measured board PqOrganisatie against the client page. Three code
causes remain on the shared ClientDetail page (PqKlant draws the same frame):

- The board draws the contact strip and the six figures across the whole page, with the tabs and the side column
  starting under them. Live drew both inside the main column, so every side card sat 167 to 390px off and the six
  figures were 100px squares with clipped labels.
- The warning form was always open in the contact strip when a client had no warnings: the form was the `v-else` of a
  button that the compact panel hides, so it rendered whenever that button did not.
- The board's header holds Log contact and New request as buttons before Edit (5 buttons); live kept them in the menu (3).

## What changes

- ClientDetail puts the contact strip (a new `PartyContactStripWidget`, the strip as a widget) and the six figures in a
  compact band (`config.band`, `config.bandDensity: "compact"`, nextcloud-vue feat/detail-band-compact); the tabs widget
  starts the body grid.
- The warning form opens only after Add a warning.
- `quickActions: ["log-contact", "new-request"]`.
