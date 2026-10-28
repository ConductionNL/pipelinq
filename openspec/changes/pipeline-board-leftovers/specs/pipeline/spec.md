# pipeline: delta for pipeline-board-leftovers

## ADDED Requirements

### Requirement: The board look pipeline header keeps the board's rhythm

In the board look the pipeline board MUST place its title and header buttons on one row, the buttons 40px high and 10px apart, with the count line 6px under that row, and the header MUST start 4px under a 24px content top.

#### Scenario: the pipeline header in the board look

- GIVEN the pipeline board in the board look
- WHEN the page renders
- THEN the title sits 28px under the content's top edge
- AND Download and New lead are 40px high on the title's row
- AND the count line sits 6px under that row

### Requirement: The board look pipeline toolbar draws chips and a bare settings link

In the board look the view chips MUST stand on the page without a band, and the pipeline settings control MUST be an underlined link without a button ground or margin, named "Pijplijninstellingen" in Dutch.

#### Scenario: the toolbar in the board look

- GIVEN the pipeline board in the board look, in Dutch
- WHEN the toolbar renders
- THEN the chips have no ground behind them
- AND the settings link reads "Pijplijninstellingen" with no ground

### Requirement: The board look stage heading and owner chip follow the board

In the board look a stage heading MUST sit on a normal line with a 12px bold count pill and the stage total line 18px in under it, and a lead card MUST draw its owner as a 26px chip in the light primary.

#### Scenario: a stage column in the board look

- GIVEN the Verkoop pipeline with a stage holding four leads
- WHEN the board renders the stage
- THEN its count pill reads 4 in 12px bold
- AND each card's owner chip is 26px in the light primary
