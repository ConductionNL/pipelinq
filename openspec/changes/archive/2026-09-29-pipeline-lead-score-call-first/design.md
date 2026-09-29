# Design: pipeline-lead-score-call-first

## Context (read at pipelinq development ec6b0277)

- The calculation `qualificationScore` (materialised) sits at `lead.configuration.x-openregister-calculations` in `lib/Settings/pipelinq_register.json`: value present +10, above 10000 +20, client +15, contact +10, source referral or partner +15, expected close date +10, priority high or urgent +10, description +5. `ProspectScoringService` scores prospects against an ideal customer profile and is unrelated.
- `LeadList.vue` wraps `CnIndexPage`; cell widgets are registered in `src/App.vue:175` (`lead-close-date`, `lead-probability`). The `/leads` page config (`src/manifest.json:2667`) lists nine columns and no score.
- `PipelineCard.vue` shows title, priority marker and value; `PipelineBoard.vue` has a table mode with sortable headers (title, type, stage, assignee, value, due date, priority, age) and a card mode.
- The lead detail page already lists `qualificationScore` in its Qualification group.

## Decisions

### D1. A cell widget, like the two beside it

`LeadScoreCell.vue` registers as `lead-score` next to `lead-probability` and renders `92` with a band label: `High` (70 and up), `Medium` (40 to 69), `Low` (under 40). The label is text, so the band is not conveyed by colour alone. Leads saved before the calculation existed show a dash, not zero.

### D2. The card shows the number only

The board card is narrow. It gets a compact badge with the number and an accessible name "Score 92, high". Colour comes from the same band function.

### D3. Sort is the list's own sort

`qualificationScore` is `facetable` and materialised, so it is stored and sortable by OpenRegister. The column is `sortable`, the table-mode header gets a `score` key, and "Call first" is a manifest quick filter that sets sort to score descending. No client-side sorting of a page of results.

### D4. The explanation is computed in the browser from the lead

`LeadScoreExplanation.vue` applies the same eight criteria to the lead it already holds and lists the ones that matched. To stop this drifting from the schema, a Vitest test reads the real `x-openregister-calculations.qualificationScore` expression from the register file and asserts the browser total equals a hand evaluation of that expression for four fixture leads. The number shown is always the stored `qualificationScore`; the explanation only annotates it, and shows "Score changed since this was calculated" when the two totals differ.

### D5. Engagement is out

Scoring on mail opens, visits or contact frequency needs data the lead object does not hold. It is a separate change if a demand row asks for it.

## Declarative-vs-imperative decision

The calculation stays declarative in the schema. The column, quick filter and widget registration are manifest and component work; nothing is added to PHP.

