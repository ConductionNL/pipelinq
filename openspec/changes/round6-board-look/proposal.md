# Proposal: round6-board-look

## Why

The drawn boards on identity.conduction.nl/screens are the UI canon. Round 6 brings every built list of Pipelinq to its board: the board look switch, the board header on every list, Dutch status words, board column order and the leads list with a win-chance bar.

## What changes

- `look: "board"` in the app manifest.
- A board header (title, count line, Downloaden, Acties, primary button) on every index page, through `pageDefaults.index` in `src/menu-layout.simple.json`.
- Texts the library prints as written are translated by the app (`src/utils/indexPageLabels.js`), and enum words go through `enumText` (`src/services/cellFormatters.js`).
- Page overlays in the simple structure set column order and labels per list.

Screens for features the app does not have yet are out of scope.
