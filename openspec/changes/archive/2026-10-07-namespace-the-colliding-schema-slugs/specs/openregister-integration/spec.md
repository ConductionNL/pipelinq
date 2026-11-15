# OpenRegister integration

## ADDED Requirements

### Requirement: This app's colliding slugs are namespaced (REQ-ORI-040)

The POS cash count SHALL be `posCashCount` and the channel thread SHALL be
`channelConversation`. Neither SHALL be `cashCount` or `conversation`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `cashCount` was answered for by shillinq's
kasadministratie Z-report as readily as by this app's drawer count, and a bare
`conversation` by hermiq's agent chat thread. Each pair shares zero declared
fields, so they are renamed apart rather than folded.

A repair step SHALL rename each row IN PLACE before the register import. The
import matches an existing schema by `(application, slug)` and CREATES a new
one when that misses, so a slug change in the shipped fragment alone does not
rename anything: it creates a second schema and orphans the first together with
every object on it, and nothing errors.

The step SHALL refuse a pair when both spellings exist, and when the old slug
is duplicated. Either case would decide which set of objects to abandon.

A refusal on one slug SHALL NOT stop the other rename. Otherwise an instance
that hand-resolved one collision would silently never get the second, and that
slug would keep answering for another app.

The app-config keys SHALL NOT move. `cashCount_schema` stays pinned to the new
slug through `SettingsLoadService::SCHEMA_CONFIG_KEYS`, because the key is live
persisted state.

#### Scenario: Both slugs are renamed in place

- **GIVEN** an install carrying `cashCount` and `conversation`
- **WHEN** the repair step runs
- **THEN** each row keeps its schema id, and so its shard table and objects.

#### Scenario: An already-namespaced install is untouched

- **GIVEN** an install already on `posCashCount` and `channelConversation`
- **WHEN** the step runs
- **THEN** it writes nothing.

#### Scenario: An ambiguous pair is refused

- **GIVEN** both `cashCount` and `posCashCount` exist
- **WHEN** the step runs
- **THEN** it warns and renames neither of that pair.

#### Scenario: A refusal does not block the other slug

- **GIVEN** `cashCount` is ambiguous and `conversation` is not
- **WHEN** the step runs
- **THEN** `cashCount` is refused and `conversation` is still renamed.
