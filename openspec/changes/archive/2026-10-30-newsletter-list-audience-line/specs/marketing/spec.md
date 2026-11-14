# marketing: delta for newsletter-list-audience-line

## ADDED Requirements

### Requirement: The newsletter list names each newsletter's audience

The newsletter list MUST show, under each newsletter's name, the segment it goes to with the segment's estimated size, or else the mailing list it goes to with its number of confirmed subscribers.

#### Scenario: a segment and a list

@e2e exclude Asserted in tests/vitest/newsletterListAudienceLine.spec.js (the config) and nc-vue's CnDataTableSecondaryRefs.spec.js (the rendering); the pixel check against board PqNieuwsbrieven covers the drawn line.

- GIVEN "Straatfeestseizoen: wat regel je" sent to segment "Verenigingen in Zuiddrecht" (estimated 412)
- AND "Afvalkalender 2027" sent to mailing list "Afvalnieuws"
- WHEN the user opens Nieuwsbrieven
- THEN the first reads "Segment: verenigingen in Zuiddrecht · 412 contacten" under its name
- AND the second reads "Mailinglijst: Afvalnieuws · <confirmed subscribers> abonnees"
