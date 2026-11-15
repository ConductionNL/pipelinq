# marketing-segmentation

## ADDED Requirements

### Requirement: Stored rules read as one group

`SegmentService` SHALL normalise a segment's `rules` before validating,
evaluating or returning it: a group keeps its shape with `type` upper-cased;
a bare leaf, a list of nodes, or an object with `children` and no `type`
SHALL read as an AND group of those nodes; empty or missing rules SHALL read
as an empty AND group. A save SHALL store the normalised tree. A read SHALL
NOT rewrite the stored segment. The validator SHALL refuse a tree deeper than
5 groups, naming the path of the first group past the cap.

#### Scenario: A bare leaf is one AND group

@e2e exclude normalisation is server-side and invisible except through the editor scenario "A segment stored as a bare condition opens as a group" in marketing-ui. Asserted by tests/Unit/Service/SegmentServiceTest.php (testBareLeafRulesReadAsOneAndGroup).
- **GIVEN** a segment stored with `rules` = `{field: "place", operator: "equals", value: "Zuiddrecht"}`
- **WHEN** its size is estimated
- **THEN** the count equals that of `{type: "AND", children: [that leaf]}`

#### Scenario: A list of leaves is one AND group

@e2e exclude same reason. Asserted by tests/Unit/Service/SegmentServiceTest.php (testLeafListRulesReadAsOneAndGroup).
- **GIVEN** a preview request with `rules` = a JSON list of two leaves
- **WHEN** it is validated
- **THEN** it passes and is counted as an AND of the two

#### Scenario: A tree past the depth cap is refused

@e2e exclude the editor cannot build past one inner group, so only an API caller reaches this. Asserted by tests/Unit/Service/SegmentServiceTest.php (testTreeDeeperThanFiveGroupsIsRefused).
- **WHEN** a tree six groups deep is validated
- **THEN** validation fails with the path of the sixth group

### Requirement: Only privileged users create or change a segment

`SegmentController` SHALL require `ObjectOwnerAccessPolicy::isPrivileged()`
(membership of `admin`, `sales`, or the app config `crm_group`) before
creating, previewing, updating or resizing a segment, and SHALL check before
any service call. Any privileged user MAY edit any segment. `createdBy` SHALL
come from the session, never from the request.

#### Scenario: A non-privileged user cannot create a segment

@e2e exclude an authorization refusal on an endpoint whose page a non-privileged user cannot open; asserted by tests/Unit/Controller/SegmentControllerTest.php (testCreateRefusesNonPrivilegedUser) and a Newman case in task 4.2.
- **GIVEN** a signed-in user outside `admin`, `sales` and the configured CRM group
- **WHEN** the user posts a valid segment to `POST /api/segments`
- **THEN** the answer is 403
- **AND** no segment is stored and no estimate is returned

#### Scenario: Another privileged user edits a segment

@e2e exclude two signed-in users in one run; asserted by tests/Unit/Controller/SegmentControllerTest.php (testUpdateAllowsAnyPrivilegedUser).
- **GIVEN** a segment created by user A in `sales`
- **WHEN** user B in `sales` saves a changed tree
- **THEN** the change is stored and `createdBy` stays A

### Requirement: Segment counts run in OpenRegister when it can answer them

When OpenRegister lists `listQuery.where`, `SegmentService` SHALL compile a
segment's tree into an OpenRegister condition group (`_where`) and count
there. A leaf on a derived signal, on a dotted array path, or under a NOT
SHALL NOT be compiled. When the root is AND and only some children compile,
those children SHALL narrow the rows in OpenRegister and the whole tree SHALL
then be evaluated in PHP on the rest. In every other case, and when
OpenRegister lacks the feature, the PHP path SHALL run. Both paths SHALL give
the same count and the same members.

#### Scenario: The board's segment is narrowed in OpenRegister

@e2e exclude which engine counted is not visible in a browser; the count is the same either way. Asserted by tests/Unit/Service/SegmentQueryCompilerTest.php (testRootAndPushesCompilableChildren).
- **GIVEN** AND(place equals Zuiddrecht, OR(industry equals horeca, industry equals detailhandel), pipelinqLastContactAt after a date)
- **WHEN** the size is estimated
- **THEN** OpenRegister receives AND(place eq Zuiddrecht, OR(industry eq horeca, industry eq detailhandel))
- **AND** the last-contact leaf is evaluated in PHP on the rows returned

#### Scenario: Both paths agree on every seed segment

@e2e exclude a parity property over seed data. Asserted by tests/Unit/Service/SegmentQueryCompilerTest.php (testCompiledAndPhpPathsAgreeOnSeedSegments).
- **GIVEN** every segment in the shipped and example seed data
- **WHEN** each is counted through OpenRegister and through the PHP path
- **THEN** the counts are equal

#### Scenario: Not equal keeps empty values

@e2e exclude operator semantics. Asserted by tests/Unit/Service/SegmentQueryCompilerTest.php (testNotEqualsCompilesWithIsNull).
- **GIVEN** customers with `type` "vereniging", "bedrijf" and no `type`
- **WHEN** the rule is `type notEquals vereniging`
- **THEN** the customers with "bedrijf" and with no `type` are counted
