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

### Requirement: The set fills the list boards

The Zuiddrecht set SHALL seed 23 bookings in the seven days from the install
day, 4 of them handled by Pieter and 3 awaiting a deposit, with the board's
rows first in time. It SHALL seed 5 journeys, 14 products (Club-app, basis as
a draft), 10 templates and 5 cash shifts.

#### Scenario: This week's bookings

- **GIVEN** the Zuiddrecht set installed today
- **WHEN** a user opens Appointments on This week
- **THEN** the count reads 23 and the first page shows the board's rows from today's 09:00 onward

### Requirement: pipelinq contributes its part of a cross-app story example set

The app SHALL ship `lib/Settings/example-sets/<set>.json` fragments in the format of OpenRegister's example-set loader, next to and independent of `pipelinq:demo:seed`. A fragment SHALL refer to cast members only through `{{cast:key.field}}`, SHALL use relative dates only, SHALL use mail addresses on the set's `.example` domain only, SHALL write no BSN, and SHALL declare only keys the target schema declares.

#### Scenario: The Zuiddrecht fragment validates statically
@e2e exclude static data check, not a UI flow: covered by tests/example-sets/validate_example_set.py

- **GIVEN** `lib/Settings/example-sets/zuiddrecht.json` and the cast in OpenRegister's `lib/Settings/example-sets/zuiddrecht.json`
- **WHEN** `python3 -I tests/example-sets/validate_example_set.py` runs against the merged register schemas
- **THEN** it SHALL report every object clean and exit 0

#### Scenario: The demo seed stays separate
@e2e exclude no UI flow: the fragment adds a file and changes neither DemoSeedService nor demo_seed_data.json

- **GIVEN** the Zuiddrecht set is installed
- **WHEN** an administrator runs `occ pipelinq:demo:seed` or its removal
- **THEN** the seed SHALL add or remove only its own objects and SHALL leave the set's clients and tickets in place
