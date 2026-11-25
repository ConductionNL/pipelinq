## 1. Fragment

- [x] 1.1 Write `lib/Settings/example-sets/zuiddrecht.json`: clients, contacts, three tickets, one interaction, one lead
- [x] 1.2 Refer to cast members only through `{{cast:...}}`; write no BSN and no literal KVK number
- [x] 1.3 Put the lead in the default pipeline through a lookup on `title` "Sales" and `isDefault`

## 2. Verification

- [x] 2.1 `tests/example-sets/validate_example_set.py` passes
- [ ] 2.2 `occ openregister:example-set:install zuiddrecht --check` on an instance with openregister#4603 exits 0, including the four tickets: the `ticket` schema is declared in `register.d/99-unify-ticket-supertype.json` but is not listed in the `pipelinq` register's schemas
  - The membership is fixed in code: `register.d/99-unify-ticket-supertype.json` now lists `ticket` and `register.d/62-programme-portfolio.json` its seven programme schemas in the `pipelinq` register, and `tests/Unit/Service/ExampleSetRegisterMembershipTest.php` checks that every schema the fragment uses is a member. The live install run itself is still to do.
- [x] 2.3 After install, daan's ticket list shows the three tickets and the lead sits in stage "Gekwalificeerd"
  - Live 25 Nov 2026 (sandbox NC 34 + MariaDB + OpenRegister main, pipelinq development, no dossiq): `occ openregister:example-set:install zuiddrecht` ends 'Every declared account, group and object is on this instance.' Signed in as daan.verbeek, the ticket list (`assignee=daan.verbeek`, RBAC and organisation scoping on) holds his tickets, six since the set grew past the first three (Vraag over de nieuwe afvalkalender, Telefoongesprek met Henk Bakker over de afvalkalender, Klacht over een moeilijke brief, Vraag over de uitstalling op de stoep, Vraag over de koppeling met het zaaksysteem, Klacht over een late betaling), and his lead "Kantoorruimte voor CloudGemeente B.V." sits on Verkoop in stage "Gekwalificeerd".
  - Finding: right after the install daan reads 0 tickets. The set writes `crm_group` = zuiddrecht-kcc through app config, and the grant reaches the schemas only when `SyncRegisterAccessJob` runs (after `php cron.php` the ticket schema's read list carries zuiddrecht-kcc and the six tickets show). On an instance with cron every 5 minutes this is a short window.
- [x] 2.4 Take `contactsUid` from the cast and set `crm_group` and `brp.allowed_groups` through `appConfig` (loader head 473758abd)
- [ ] 2.5 A `pipelinq:contacts:sync` writes a Contacts card per client with the cast's uid
