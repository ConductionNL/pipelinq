## ADDED Requirements

### Requirement: A value typed by hand stays beside the calculated value

When a lead line is created, changed or deleted, the system MUST write the sum of the lead's line totals as its calculated value, and MUST write it as the lead's value too unless the value was typed over the calculated one. A lead without lines MUST keep the value typed by hand. While the value differs from the calculated value, the lead page MUST show the calculated value under it and offer "Use calculated value", which MUST set the value to the calculated value.

#### Scenario: A line is added

- GIVEN a lead with no lines and no value
- WHEN a line with total 3.000 is added
- THEN the lead's value and calculated value MUST be 3.000

#### Scenario: A typed value stays

- GIVEN a lead with lines adding up to 3.000 and a value typed as 6.000
- WHEN a line changes and the lines add up to 3.200
- THEN the value MUST stay 6.000 and the calculated value MUST be 3.200
- AND the lead page MUST show "Calculated from the lines" 3.200 and "Use calculated value"

#### Scenario: Use calculated value

- GIVEN that lead
- WHEN the user chooses "Use calculated value"
- THEN the value MUST be 3.200 and the action and the calculated line MUST no longer show
