# forecast-table-heads Specification

## Purpose
The forecast page's tables let a salesperson sort and filter the rows the page already holds from the column heads, without a round trip to the server.

## Requirements

### Requirement: The forecast tables sort and filter their rows

The forecast page's quarter table SHALL be a CnDataTable whose owner and figure columns sort when their head is pressed, and whose heads filter: the owner on contains, each figure on a range. The Override column SHALL neither sort nor filter. Sorting and filtering SHALL act on the rows the page already holds.

#### Scenario: Sort by commit

- **GIVEN** the quarter table with three owners
- **WHEN** the handler presses the Commit head
- **THEN** the rows run from the lowest commit to the highest, and pressing again reverses them

#### Scenario: Filter on a figure range

- **GIVEN** the quarter table
- **WHEN** the handler filters Commit from 20000 to 40000
- **THEN** only the owners whose commit lies in that range stay
