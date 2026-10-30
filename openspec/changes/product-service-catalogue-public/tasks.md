# Tasks: product-service-catalogue-public

## 1. Schema
- [ ] 1.1 `lib/Settings/pipelinq_register.json` `product`: add `audience` (array, enum burger, ondernemer, both; SDG), `uplReference` (uri), `tariffs` (array of {label, amount, currency, conditions}), `publishFrom`, `publishUntil` (date), `summary`, `contentBlocks` (array of {heading, body}), `applyUrl` (uri), `channels` (array); all optional so existing products stay valid (additive, no `format` added to existing fields) (V1)
- [ ] 1.2 `type` enum gains `service` if it lacks it (V1)
- [ ] 1.3 Register annotation test: the new properties are declared and optional (V1)

## 2. Publication
- [ ] 2.1 Check OpenRegister's public object API (`published` / `depublished` on objects, anonymous reads on a published schema) and whether it can filter on a date window; if not, set OpenRegister's `published`/`depublished` from `publishFrom`/`publishUntil` on save through the existing object event listener in pipelinq (V1)
- [ ] 2.2 Make the `product` schema publicly readable for published objects only, scoped to `type: service` (OpenRegister schema authorisation), and verify anonymously: published current service 200, concept 404, scheduled 404, goods 404 (V1)
- [ ] 2.3 Point the spec's public API requirement at OpenRegister's endpoint in the spec when archiving (the `/api/pdc/products` path in its scenarios is not built and not needed) (V1)

## 3. Pages
- [ ] 3.1 Product page (PqProduct): for `type: service` a Catalogue card with audience, UPL reference, publication window, apply link and the tariff table; products of other types unchanged (V1)
- [ ] 3.2 Products list (PqProducten): a type filter and a Published column for services (V1)
- [ ] 3.3 l10n nl and en (V1)

## 4. UPL list
- [ ] 4.1 A repair step or occ command that imports the UPL reference list (CSV from the national source) into an OpenRegister lookup so `uplReference` is picked from a list (V1)

## 5. Close
- [ ] 5.1 Correct the spec's front matter from `done` to the measured state; flip `prod-service-catalogue` to built when 1-3 are live (V1)
