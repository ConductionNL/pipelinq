# pipeline: delta for pipeline-board-cards

## ADDED Requirements

### Requirement: The board look draws the kanban's lead cards as the board does

In the board look each lead card MUST show its title, its value, its chance (the lead's own, else its stage's) in the stage's colour, and under a rule the days in its stage and its owner's initials; a stale lead MUST be outlined in the error colour.

#### Scenario: a lead in Benaderd

- GIVEN Zorggroep Merwede in stage Benaderd (30%), € 64.000, owned by Fatma Yildiz, 6 days in the stage
- WHEN the board shows the Verkoop pipeline in the board look
- THEN its card reads "€ 64.000", "30% kans", "6 dagen in fase" and "FY"
