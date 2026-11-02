# marketing-ui

## ADDED Requirements

### Requirement: A segment groups its rules

The segment editor SHALL let a marketer combine conditions in groups. Every
group SHALL show a radio choice "How the conditions combine" with "Match all
conditions" (AND) and "Match any condition" (OR). The root group SHALL offer
Add condition and Add group; an inner group SHALL offer Add condition and a
written Remove group, and SHALL NOT offer Add group. Each row SHALL show its
connector ("Where", then "and" or "or"), and a row holding an inner group
SHALL show the caption "all of these" or "one of these". Every condition SHALL
have a Remove condition button. The editor SHALL be nc-vue's
`CnConditionGroupEditor` with pipelinq's fields and operators.

#### Scenario: Build a group

- **GIVEN** a privileged marketer creating a segment for the audience Customers
- **AND** one condition "Place equals Zuiddrecht"
- **WHEN** the marketer clicks Add group
- **THEN** an indented group appears after the condition, holding one empty condition
- **AND** the group header shows the combinator choice and Remove group
- **AND** the group offers Add condition but no Add group
- **AND** the estimate note reads "Complete every condition to see the estimate."

#### Scenario: Mix AND and OR

- **GIVEN** the root set to "Match all conditions" with "Place equals Zuiddrecht"
- **AND** an inner group set to "Match any condition" with "Industry equals horeca" and "Industry equals detailhandel"
- **THEN** the root rows read "Where" and "and", with the caption "one of these" before the group
- **AND** the inner rows read "Where" and "or"
- **AND** the preview request carries AND(place, OR(industry, industry))

#### Scenario: Remove a condition or a group

- **GIVEN** the tree of the previous scenario
- **WHEN** the marketer removes "Industry equals detailhandel"
- **THEN** the inner group keeps one condition
- **WHEN** the marketer then clicks Remove group
- **THEN** the group and its condition are gone and the root keeps "Place equals Zuiddrecht"

#### Scenario: The preview counts the whole tree

- **GIVEN** every condition in every group is filled in
- **WHEN** the rules stop changing for 400 ms
- **THEN** one `POST /api/segments/preview` carries the whole tree
- **AND** the note shows "Estimated members: N" with the count the server returns
- **AND** while any condition or group is unfinished, no preview is sent

#### Scenario: Saving keeps the groups

- **GIVEN** a complete, valid tree with an inner group
- **WHEN** the marketer clicks Create segment
- **THEN** `POST /api/segments` stores `rules` as the tree, inner group included
- **AND** reopening the segment shows the same groups, combinators and conditions
- **AND** Create segment stays disabled while an inner group has no conditions

#### Scenario: An existing flat segment reads as one group

- **GIVEN** a stored segment whose `rules` is an AND of three conditions, like every Zuiddrecht example segment
- **WHEN** a marketer opens it
- **THEN** the root group shows the three conditions with "Match all conditions" chosen
- **AND** Add condition and Add group are offered
- **AND** saving without changes stores the same tree

#### Scenario: A segment stored as a bare condition opens as a group

- **GIVEN** a stored segment whose `rules` is one condition object without `type`
- **WHEN** a marketer opens it
- **THEN** the root group shows that one condition, with Add condition and Add group
- **AND** saving stores `{type: AND, children: [the condition]}`

#### Scenario: A deeper tree from the API stays readable

- **GIVEN** a segment written through the API with a group inside an inner group, and a NOT group
- **WHEN** a marketer opens it
- **THEN** every level renders, and Add group shows on the root only
- **AND** the NOT group shows as a locked row "These conditions are kept as written" with Remove
