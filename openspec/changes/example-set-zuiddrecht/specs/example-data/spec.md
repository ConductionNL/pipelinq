## ADDED Requirements

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
