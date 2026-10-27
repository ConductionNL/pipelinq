# pipeline Delta: only-the-drawn-pipelines

## ADDED Requirements

### Requirement: The default pipelines are only created in an empty register

The repair step SHALL create the shipped default pipelines only when the register holds no pipeline at all, and SHALL NOT create them when it holds any pipeline, whatever its title. This narrows Default Pipelines [MVP]: "MUST NOT be recreated if it already exists" now reads "is not created while any pipeline exists".

#### Scenario: A fresh install gets the defaults
@e2e exclude repair-step behaviour; covered by DefaultPipelineServiceTest::testRepairWithoutAUserWritesAsTheSystemAccount
- **GIVEN** a register with no pipeline
- **WHEN** the repair step runs
- **THEN** the shipped default pipelines are created

#### Scenario: Removed defaults stay removed
@e2e exclude repair-step behaviour; covered by DefaultPipelineServiceTest::testARegisterWithItsOwnPipelinesGetsNoDefaults
- **GIVEN** a register that holds Verkoop and no "Sales Pipeline"
- **WHEN** the repair step runs again
- **THEN** no pipeline is created

### Requirement: Mock pipelines can be removed when nothing is on them

`occ pipelinq:pipelines:remove-mocks` SHALL remove each known mock pipeline that no lead and no ticket is on. Known mocks are the mock register's pipelines by slug, and the shipped defaults and demo seed's pipelines by exact title and description. The command SHALL count objects past RBAC and organisation scoping. It SHALL keep and report a mock that has objects on it, report a removal OpenRegister refuses, and report more than one default pipeline. With `--dry-run` it SHALL remove nothing.

#### Scenario: Unused mocks go, the drawn pipelines stay
@e2e exclude maintenance command; covered by MockPipelineCleanupServiceTest::testRemovesUnusedMocksAndKeepsTheSetsPipelines
- **GIVEN** the shipped, demo and mock pipelines with nothing on them, plus Verkoop, Vragen en meldingen and Evenementen en vergunningen
- **WHEN** an admin runs `occ pipelinq:pipelines:remove-mocks`
- **THEN** only Verkoop, Vragen en meldingen and Evenementen en vergunningen remain, and Vragen en meldingen is the only default

#### Scenario: A mock in use is kept
@e2e exclude maintenance command; covered by MockPipelineCleanupServiceTest::testKeepsAMockThatHasObjectsOnIt
- **GIVEN** "Sales Pipeline" with a lead on it
- **WHEN** the command runs
- **THEN** "Sales Pipeline" stays and the report names the lead objects on it

#### Scenario: Somebody's own pipeline is never a mock
@e2e exclude maintenance command; covered by MockPipelineCleanupServiceTest::testOnlyTheExactKnownPipelinesAreMocks
- **GIVEN** a pipeline titled "Sales Pipeline" with a description its owner wrote
- **WHEN** the command runs
- **THEN** it is left alone
