# example-data Specification

## Purpose
Makes the example records load cleanly on any instance. Every example record imports, user fields name an account that exists on the server, and reference records that only look like examples are marked as example data, so an administrator can try pipelinq on realistic records and remove them again.

## Requirements

### Requirement: Every example record imports

When the example records are loaded, every user field (`format: user`, `format: username` or a nextcloud-user reference) SHALL name an account that exists on the server. A value that is a real account SHALL stay. Any other value SHALL become the user who loads the examples, or SHALL be left out when nobody is signed in (the occ path). No example record SHALL be skipped for a user that does not exist.

#### Scenario: An administrator loads the examples

- GIVEN a server whose only account is `admin`
- WHEN `admin` loads the example data from the setup wizard
- THEN every user field of every example record SHALL be `admin` or empty
- AND OpenRegister SHALL skip no record for a `format: user` mismatch

@e2e exclude asserted against the real descriptor and the merged schemas in tests/Unit/Service/Demo/DemoRegisterImporterTest.php.

### Requirement: Example-looking reference records are example data

The skills "Vergunningen" and "WMO / Zorg", the SLA policy "Goud-tier klant-SLA" and the segment "Advice customers without a product" SHALL live in `pipelinq_example_register.json` with their slugs unchanged, and SHALL NOT be in the register descriptor or among the default skills. A fresh install that chose no example data SHALL NOT receive them. An existing install SHALL keep the stored records it already has.

#### Scenario: A fresh install without example data

- GIVEN a fresh install whose administrator chose "None" for example data
- WHEN the register is imported and the default skills are created
- THEN none of these four records SHALL exist

@e2e exclude a property of the descriptors and of DefaultSkillService, asserted by tests/Unit/Settings/RegisterCarriesNoExampleDataTest.php and tests/Unit/Service/DefaultSkillServiceTest.php.

#### Scenario: Loading the example data

- GIVEN the same install
- WHEN the administrator loads the example data
- THEN the four records SHALL arrive under their old slugs
