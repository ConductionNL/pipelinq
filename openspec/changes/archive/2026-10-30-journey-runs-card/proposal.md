# Proposal: journey-runs-card

## Summary

Board PqJourney draws "Wat deze journey deed" as a titled card with "Elk contact dat bereikt is en elk contact dat geweigerd is" under the title. On :8080 (30 Oct) the runs render in the Overview stack inside a card with no title row: the library only titles an app widget whose registry entry asks for the card (`chrome: "card"`), and JourneyRunsWidget did not. The entry now asks for it, the manifest gives the line as the card's `subtitle`, and the section leaves its own copy of the line out inside the card.

## Scope

- `src/registry.js` (JourneyRunsWidget `chrome: "card"`, `showSubtitle: false`), `src/components/marketing/JourneyRunsSection.vue` (`showSubtitle`), `src/manifest.d/79-marketing-journeys.json` (`subtitle`).
- No new strings.
