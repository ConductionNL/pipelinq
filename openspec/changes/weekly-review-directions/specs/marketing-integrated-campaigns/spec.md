# marketing-integrated-campaigns: delta for weekly-review-directions

## ADDED Requirements

### Requirement: Each weekly review highlight says which way its figure moved

The weekly review MUST store, for each highlight line, whether its figure rose, fell or held against the week before. The page MUST draw an up arrow before a line that rose and a down arrow before a line that fell.

#### Scenario: clicks fell, views rose

@e2e exclude Asserted in tests/Unit/Service/Marketing/WeeklyReviewServiceTest.php and tests/vitest/weeklyReviewDirection.spec.js; the pixel check against board PqWeekoverzicht covers the drawn arrows.

- GIVEN last week's mailings drew 2 clicks and the week before drew 3
- AND last week's posts were seen 120 times and the week before not at all
- WHEN the review is composed
- THEN the mail line is marked down and the social line up
- AND the What moved card shows a red down arrow before the mail line and a green up arrow before the social line

#### Scenario: a review stored before directions existed

@e2e exclude Asserted in tests/vitest/weeklyReviewDirection.spec.js.

- GIVEN a stored review with highlights and no directions
- WHEN the user opens the weekly review
- THEN every line shows without an arrow
