## 1. Fragment

- [x] 1.1 Write `lib/Settings/example-sets/zuiddrecht.json`: clients, contacts, three tickets, one interaction, one lead
- [x] 1.2 Refer to cast members only through `{{cast:...}}`; write no BSN and no literal KVK number
- [x] 1.3 Put the lead in the default pipeline through a lookup on `title` "Sales" and `isDefault`

## 2. Verification

- [x] 2.1 `tests/example-sets/validate_example_set.py` passes
- [ ] 2.2 `occ openregister:example-set:install zuiddrecht --check` on an instance with openregister#4603 exits 0, including the four tickets: the `ticket` schema is declared in `register.d/99-unify-ticket-supertype.json` but is not listed in the `pipelinq` register's schemas
- [ ] 2.3 After install, daan's ticket list shows the three tickets and the lead sits in stage "Gekwalificeerd"
- [ ] 2.4 Replace the `contactsUid` stand-ins once the loader can create Contacts vCards, and set `crm_group` once a fragment can set app config
