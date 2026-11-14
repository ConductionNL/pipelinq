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
- [ ] 1.4 Live, after nextcloud-vue 24t2s: read the three chips on PqContracten, PqFlows and PqEvenement
