## ADDED Requirements

### Requirement: A BRP lookup can verify a client's identity

A BRP lookup with `verifiedClient` that finds the person MUST record on that client `identityVerifiedAt` (when), `identityVerifiedVia: BRP`, and the `identityCheck` the employee gave. The purpose binding and the audit record MUST be as for any lookup.

#### Scenario: Pieter verifies Sanne in a call
- GIVEN a call with Sanne de Vries, BSN 999994001 in the BRP
- WHEN Pieter looks her up from "Wie is het" with "Geboortedatum en adres gevraagd"
- THEN Sanne's client reads Gecontroleerd "Geboortedatum en adres gevraagd" and Bron "BRP, opgezocht" with today's date

### Requirement: The lookup reads the person it asked for

When the source answers several people, the lookup MUST read the person whose `burgerservicenummer` is the BSN asked for, and the first person when none of them carries it. It MUST read the name from both the English shape (`name`) and HaalCentraal's Dutch shape (`naam`).

#### Scenario: The source answers several people
- GIVEN a BRP answer with two persons, the second carrying `burgerservicenummer` 999993653, the BSN asked for
- WHEN the lookup reads the answer
- THEN it reads the second person

#### Scenario: The Dutch shape
- GIVEN a HaalCentraal answer whose person carries `naam.voornamen` and `naam.geslachtsnaam` and no `name`
- WHEN the lookup reads the answer
- THEN it reads the given names and surname from `naam`
