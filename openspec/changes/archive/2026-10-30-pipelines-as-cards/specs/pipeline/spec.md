# pipeline: delta for pipelines-as-cards

## ADDED Requirements

### Requirement: The pipelines list shows each pipeline with its stages

The Pipelines page MUST open as cards, one per pipeline. Each card shows its name, its description, its number of stages, when it was made, and its stages as chips in order.

#### Scenario: Verkoop

- GIVEN pipeline Verkoop with six stages
- WHEN the Pipelines page opens
- THEN Verkoop's card reads "6 fases" and shows the chips Nieuw, Benaderd, Gekwalificeerd, Voorstel, Gewonnen and Verloren
