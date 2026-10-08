# Design: the Prospects page

## Page type

The page is `type: custom` with component `ProspectsView`. A declarative `index` page lists one OpenRegister schema with manifest-declared columns. Prospects are not OpenRegister objects: they come from the prospect API, which searches KvK and OpenCorporates and scores each hit against the ideal customer profile. The row action also creates a different entity (a `client`) from the one listed. Neither fits `index`, which the `_note` on the page entry records.

## Data

`ProspectsView` reads `store/modules/prospect.js`, the store the dashboard widget used. Refresh calls the store with the cache bypassed. Sorting runs over the whole list before paging, so a column sort orders every page.

## Row action

"Add as client" follows the Prospect-to-Client Conversion requirement in the main spec: it creates an organisation `client` from the trade name, address and KvK number, and the row leaves the list because discovery excludes existing clients.

## Navigation

The manifest fragment declares a top-level `Prospects` menu entry. `src/menu-layout.json` relocates it into the `Dashboard` group, which the menu labels Sales, next to Leads, Pipeline, Clients and Contacts. The Modules page lists it under Sales.
