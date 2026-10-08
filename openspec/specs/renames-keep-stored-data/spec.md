---
status: done
---

# renames-keep-stored-data Specification

## Purpose
Describes, after the fact (spec round of 2026-10-07), how pipelinq keeps a customer's existing records readable when an app update renames a property, an enum value or a schema slug. OpenRegister stores every schema property as a column in a per-schema table and never renames a column; its import matches a schema by application and slug and creates a new schema when the slug changed. A rename in the shipped register alone therefore leaves the data under the old name while every read looks at the new one, and nothing errors. Pipelinq's repair steps (registered in `appinfo/info.xml`, run by `occ upgrade`) move the data with the rename. This covers renames pipelinq ships; a field an administrator renames in OpenRegister's schema editor is not covered.
## Requirements

### Requirement: A renamed property SHALL carry its stored values to the new column

`lib/Repair/RenameDutchColumns.php` SHALL, for every shard table of the `pipelinq`, `pipelinq-portal` and `sla` registers, move each column in its map (for example `bedrag` to `amount`, `einddatum` to `end_date`) to the new name. It SHALL rename the column when only the old one exists, SHALL copy values into the new column where it already exists and is empty while leaving the old column in place, SHALL refuse a table where two old columns target one new column, and SHALL delete nothing. A second run SHALL change nothing.

@e2e exclude after-the-fact spec of a repair step (spec round 2026-10-07); covered by the repair step's PHPUnit test.

#### Scenario: Amounts survive the move to English names
- WHEN an install holding contracts with values in column `bedrag` is upgraded
- THEN after the upgrade the same values MUST be in column `amount`
- AND reading the contracts through the app MUST show those amounts

#### Scenario: Both columns already exist
- WHEN OpenRegister has already added an empty `amount` column next to a filled `bedrag`
- THEN the repair MUST copy the values into `amount` where it is empty
- AND MUST leave `bedrag` in place

#### Scenario: Running it twice
- WHEN the repair runs on an install it has already migrated
- THEN it MUST change no row and no column

### Requirement: A renamed enum value SHALL be rewritten in the stored rows

`lib/Repair/RenameDutchPipelinqValues.php` SHALL rewrite stored Dutch enum values to their English replacements, matching on the column as well as the value, so a value shared by two columns is only rewritten where the map names it. Columns that map onto an outside standard (`zgwResourceType`, `actorType`) SHALL keep the standard's vocabulary.

@e2e exclude after-the-fact spec of a repair step (spec round 2026-10-07); covered by the repair step's PHPUnit test.

#### Scenario: A filter on the new value finds the old rows
- WHEN rows were saved with a Dutch status value and the update renames that value
- THEN after the upgrade a filter on the English value MUST return those rows

### Requirement: A renamed schema slug SHALL keep the schema and its objects

`lib/Repair/RenameCollidingSchemaSlugs.php` SHALL rename pipelinq's schemas whose slug collided with another app (`cashCount` to `posCashCount`, `conversation` to `channelConversation`, `contract` to `salesContract`, `portalAccount` to `crmPortalAccount`, and the rest of its map) in place, before the register import runs, so the import updates the existing schema instead of creating an empty second one. It SHALL refuse, with a warning, when both the old and the new slug exist. `RenameLoyaltyAccountSchemaSlug` and `RenameTimeEntrySchemaSlug` SHALL do the same for their single slug.

@e2e exclude after-the-fact spec of a repair step (spec round 2026-10-07); covered by the repair step's PHPUnit test.

#### Scenario: Contracts keep their objects after the slug change
- WHEN an install with contracts under schema slug `contract` is upgraded
- THEN the same schema MUST now have slug `salesContract` and keep its id
- AND every existing contract MUST still be listed in the app

#### Scenario: Both slugs already exist
- WHEN schemas with slug `contract` and `salesContract` both exist for pipelinq
- THEN the repair MUST rename neither and MUST log that it refused
