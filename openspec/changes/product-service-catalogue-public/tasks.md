# Tasks: product-service-catalogue-public

## 1. Schema
- [x] 1.1 `lib/Settings/pipelinq_register.json` `product`: add `audience` (array, enum burger, ondernemer, both; SDG), `uplReference` (uri), `tariffs` (array of {label, amount, currency, conditions}), `publishFrom`, `publishUntil` (date), `summary`, `contentBlocks` (array of {heading, body}), `applyUrl` (uri), `channels` (array); all optional so existing products stay valid (additive, no `format` added to existing fields) (V1)
  - Built 2026-11-13 in `lib/Settings/register.d/95-product-service-catalogue.json` (product 1.2.0): `summary`, `contentBlocks`, `uplReference`, `publishFrom`, `publishUntil`, `applyUrl` and `channels` (online, desk, phone, post), all optional. `tariffs`, `audience`, `publishedAt` and `languages` already ship in `94-product-catalogue-fields.json` with another shape than this task names (tariff rows are label, price, validFrom, note; audience is resident, business). They are kept as they are rather than changed under existing products.
- [x] 1.2 `type` enum gains `service` if it lacks it (V1)
  - Built 2026-11-13: nothing to add; `type` already has `product` and `service` (`lib/Settings/pipelinq_register.json`).
- [x] 1.3 Register annotation test: the new properties are declared and optional (V1)
  - `tests/Unit/Settings/ProductServiceCatalogueSchemaTest.php`, through the loader the import uses.

## 2. Publication
- [x] 2.1 Check OpenRegister's public object API (`published` / `depublished` on objects, anonymous reads on a published schema) and whether it can filter on a date window; if not, set OpenRegister's `published`/`depublished` from `publishFrom`/`publishUntil` on save through the existing object event listener in pipelinq (V1)
  - Measured 2026-11-13 in openregister `docs/features/deprecate-published-metadata.md` and `docs/Features/access-control.md`: OpenRegister no longer has object-level `published`/`depublished`; publication is a conditional RBAC rule with `$now`. So no listener is needed. The rule for 2.2 is therefore two `public` read rules on product in `99-zzz-register-access.json` (type service, status active, `publishFrom` `$lte` `$now`, and `publishUntil` `$exists: false` or `$gt` `$now`; there is no `$or`). `$now` is `Y-m-d H:i:s`, so a date counts from the start of its day and `publishUntil` is the first day the service is no longer public.
- [ ] 2.2 Make the `product` schema publicly readable for published objects only, scoped to `type: service` (OpenRegister schema authorisation), and verify anonymously: published current service 200, concept 404, scheduled 404, goods 404 (V1)
  - NOT SHIPPED, 2026-11-13: opening a read to the public group is an access change, and the verify lane holds those for the owner's decision. The rules under 2.1 were built and then taken out of the build PR so the schema fields and pages can merge on their own. This task is the owner's decision plus that one block, and then the anonymous check on a live instance (published current 200, concept 404, scheduled 404, goods 404).
- [ ] 2.3 Point the spec's public API requirement at OpenRegister's endpoint in the spec when archiving (the `/api/pdc/products` path in its scenarios is not built and not needed) (V1)

## 3. Pages
- [x] 3.1 Product page (PqProduct): for `type: service` a Catalogue card with audience, UPL reference, publication window, apply link and the tariff table; products of other types unchanged (V1)
  - `product-catalogue` data card in the Overview tab, `visibleWhen` type service: summary, UPL reference, publication window, apply link, channels. The tariff table (`product-tariffs`) already shows for every product.
- [x] 3.2 Products list (PqProducten): a type filter and a Published column for services (V1)
  - The type quick filters (Products, Services) already exist; the list gains a Published from column.
- [x] 3.3 l10n nl and en (V1)
  - en and nl for every new schema string and card title; the short labels in de, es, fr and it too.

## 4. UPL list
- [ ] 4.1 A repair step or occ command that imports the UPL reference list (CSV from the national source) into an OpenRegister lookup so `uplReference` is picked from a list (V1)

## 5. Close
- [ ] 5.1 Correct the spec's front matter from `done` to the measured state; flip `prod-service-catalogue` to built when 1-3 are live (V1)
