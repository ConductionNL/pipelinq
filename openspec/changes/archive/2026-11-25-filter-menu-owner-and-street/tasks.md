# Tasks: filter-menu-owner-and-street

- [x] 1.1 Contracts list: owner in the Filter menu, `ownerId` facetable
  - Files: src/manifest.d/96-contracts.json, lib/Settings/register.d/96-contract-renewal.json
  - Test: tests/vitest/filterMenuOwnerAndStreet.spec.js
- [x] 1.2 Flows page: Filter button with the owner
  - Files: src/manifest.json
  - Test: tests/vitest/filterMenuOwnerAndStreet.spec.js
- [x] 1.3 Event registrations: the address chip reads "Street"
  - Files: src/manifest.d/78-campaign-events.json
  - Test: tests/vitest/filterMenuOwnerAndStreet.spec.js
- [x] 1.4 Live, after nextcloud-vue 24t2s: read the three chips on PqContracten, PqFlows and PqEvenement
  - Files: src/manifest.d/96-contracts.json, src/manifest.json (`filterLabels` for the owner)
  - Test: tests/vitest/filterMenuOwnerAndStreet.spec.js
  - Live 25 Nov 2026 (sandbox NC 34 + MariaDB, OpenRegister main 119f4810, nextcloud-vue 2.230.0, Zuiddrecht set; Playwright as admin). First read on development: the Owner filter works, but the chips read "Owner ID: Pieter Jansen" (the schema title of `ownerId`) and "owner: me" (the raw key): nextcloud-vue names a chip by `filterLabels`, the schema title or the column, never by the `filterMenuFields` label. With `filterLabels` for `ownerId` and `owner` set to "Owner": Contracts "Owner: Pieter Jansen" (nl "Eigenaar: Pieter Jansen"), Flows "Owner: me" (nl "Eigenaar: ik", the flows are the admin's own), and the event's Registrations tab, Address column filtered on Lindelaan, "Street: Lindelaan" (nl "Straat: Lindelaan").
  - Seen, not changed: on the first opening of the Contracts Filter menu the owner reads "pieter" (the uid) until nextcloud-vue has loaded the display name; the next opening reads "Pieter Jansen". That lazy load is nextcloud-vue's.
