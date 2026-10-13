## Why

The Zuiddrecht demo municipality tells one story across apps. OpenRegister PR #4603 adds `occ openregister:example-set:install <set>`, which reads `lib/Settings/example-sets/<set>.json` from every enabled app and owns the cast of residents, businesses and employees. pipelinq's own example data (`occ pipelinq:demo:seed`, `lib/Settings/demo_seed_data.json`) brings its own invented people, so a demo that installs both shows two worlds that never meet.

## What changes

- `lib/Settings/example-sets/zuiddrecht.json`: pipelinq's fragment of the set, 14 objects. The cast's residents Henk, Sanne and Fatima and the businesses Slagerij Van der Berg, CloudGemeente B.V. and Installatiebedrijf Van Dam as clients, with Jan-Willem, Mark and Karin as the businesses' contacts. The work of KCC employee daan: a phone question from Henk with the call itself as an interaction, a question from Sanne about her Woo request on the Lindelaan cycle path, a complaint from Fatima about a letter she cannot read, and a lead for CloudGemeente B.V. with Mark as contact in the default pipeline "Sales". All dates are relative.
- `tests/example-sets/validate_example_set.py`: a static check of the fragment against the merged register schemas, with no instance needed.
- `pipelinq:demo:seed` and `demo_seed_data.json` are unchanged.

## Depends on

ConductionNL/openregister#4603 (the loader and the cast). Until it merges, the fragment is inert: nothing reads the folder.

## Agents and contact identity

- `appConfig` sets `crm_group` and `brp.allowed_groups` to the cast's KCC group (`{{cast:kcc.gid}}`, `zuiddrecht-kcc`), which daan is in through the cast. So daan works as a CRM agent and may look people up in the BRP. The loader records the old values and remove puts them back.
- `client.contactsUid` and `contact.contactsUid` are required and name a Nextcloud Contacts card. Each comes from the cast (`{{cast:<key>.contactsUid}}`, `example-set-zuiddrecht-<key>`), so every app that names a cast member uses the same uid. The loader writes no Contacts card: a later `pipelinq:contacts:sync` writes a card with that uid. Until then the uid links to no card; pipelinq only requires the field.
- Sanne's question is not yet linked to her Woo case: no dossiq fragment exists to look the case up in.

## Impact

Data only, no code.
