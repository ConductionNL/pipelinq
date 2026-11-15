# openregister-integration Specification (delta)

## Purpose

Repair steps run because info.xml names them. This delta makes the rule
checkable for abstract bases, which must never be named.

## ADDED Requirements

### Requirement: Every repair step is registered and no abstract base is

Every concrete class under `lib/Repair/` that is a Nextcloud repair step SHALL
be named in the `<post-migration>` block of `appinfo/info.xml`. An abstract
repair base SHALL NOT be named in any repair block, and SHALL carry a
`hydra-gate-98 held:` marker in info.xml that says why.

#### Scenario: A new repair step is written but not named

- GIVEN a concrete repair step class under `lib/Repair/`
- WHEN info.xml does not name it in `<post-migration>`
- THEN the repair step registration test fails and names the class

#### Scenario: An abstract base is shared by two steps

- GIVEN `CreateServiceGroup` is abstract and two named steps extend it
- WHEN the release gates run
- THEN info.xml does not name `CreateServiceGroup`
- AND its held marker gives the reason, so gate-98 reports it as held
