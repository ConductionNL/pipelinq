## ADDED Requirements

### Requirement: Team today shows each colleague once, by name

`GET /api/routing/team-load` MUST return one row per agent profile user id, the first profile read when there are more. A row's `displayName` MUST be the profile's `displayName`, else the Nextcloud user's display name, else the user id.

#### Scenario: A profile without a display name
- GIVEN an agent profile for user `fatma.yildiz` without `displayName`, and that Nextcloud user is named "Fatma Yildiz"
- WHEN the queue's Team today card reads the team load
- THEN the row reads "Fatma Yildiz"

#### Scenario: Two profiles for one colleague
- GIVEN two agent profiles with user id `fatma`
- WHEN the team load is read
- THEN it holds one row for `fatma`, from the first profile
