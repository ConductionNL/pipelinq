# First-time setup: example data only on request

Delta over `specs/first-time-setup`.

## MODIFIED Requirements

### Requirement: REQ-SETUP-PIP-008 — Optional Demo-Data Seed

The system SHALL provide an idempotent demo-data seed invocable two ways from one write path: an occ command `pipelinq:demo:seed` and the setup wizard's example data choice. The seed SHALL create a coherent linked dataset (clients, contacts, leads across pipeline stages, tickets, products, point of sale, bookings and marketing records) such that lists, dashboards and the 360° client view render populated. Seeded objects SHALL be identifiable as demo data, re-running SHALL create no duplicates, and a removal mode SHALL delete exactly the seeded objects.

The register descriptor (`pipelinq_register.json` and `register.d/*.json`) SHALL carry reference data only. Example records SHALL ship in an on-demand descriptor of type `mock`, imported under the configuration identity `pipelinq.demo`, and never by install, upgrade or the provisioning step.

**Feature tier**: MVP

#### Scenario: Declining example data means no example data

- GIVEN a clean install
- WHEN the administrator picks "None" at the example data step and completes setup
- AND the register is provisioned, upgraded or re-provisioned
- THEN no example record MUST exist
- AND only the reference records of the register descriptor MUST have been imported

@e2e exclude a clean install per test is not available to the browser suite. Asserted by tests/Unit/Settings/RegisterCarriesNoExampleDataTest.php (testTheRegisterSeedsReferenceDataOnly, testTheExampleDescriptorIsOnDemand).

#### Scenario: Seed on a clean install

- GIVEN a clean install with provisioned registers
- WHEN `occ pipelinq:demo:seed` runs, or the administrator picks example data in the wizard
- THEN clients, leads, tickets, products, point of sale, booking and marketing records MUST exist, linked so customer-360 and the dashboards render populated
- AND every seeded object MUST be identifiable as demo data

#### Scenario: Idempotent re-run

- GIVEN a completed seed
- WHEN the command runs again
- THEN no duplicate objects MUST be created

#### Scenario: Offered as an optional wizard step

- GIVEN an admin in the first-time setup wizard
- WHEN the optional steps are presented
- THEN a demo-data action MUST be offered, invoking the same seeding service as the occ command
- AND skipping it MUST NOT block setup completion

#### Scenario: Removal deletes exactly the seed

@e2e exclude removal is an `occ pipelinq:demo:seed --remove` CLI invocation with no HTTP or UI entry point, and running it inside the browser suite would delete the fixture the rest of the run depends on. Asserted by tests/Unit/Service/DemoSeedServiceTest.php and tests/Unit/Service/Demo/DemoRegisterImporterTest.php.

- GIVEN a seeded install with additional real data
- WHEN the removal mode runs
- THEN all seeded objects MUST be deleted and no real object MUST be touched

#### Scenario: Removal also clears what an old register seeded

@e2e exclude same CLI-only reason as above. Asserted by tests/Unit/Service/Demo/DemoRegisterImporterTest.php (testRemoveDeletesOnlyUnchangedExampleRecords).

- GIVEN an install that received example records from an earlier register descriptor
- WHEN `occ pipelinq:demo:seed --remove` runs
- THEN every such record whose schema, slug and name still match the example descriptor MUST be deleted
- AND a record whose name was changed since MUST be kept
