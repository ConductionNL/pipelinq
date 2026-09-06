# Tasks

## 1. The schema

- [x] 1.1 `timeEntry` -> `billingTimeEntry`, key AND `slug`, in both fragments
      and in the register's schema list.
- [x] 1.2 Every seedData `"schema"` reference moves with it, in the fragments
      and the mock register. A rename that reaches the schema and not its seed
      data imports rows against a slug nothing declares.
- [x] 1.3 A `timeEntry` property naming the humaniq booking: plain uuid, no
      `$ref`, per ADR-062 rule 7.
- [x] 1.4 The app-config key `timeEntry_schema` deliberately does not move.

## 2. The code sites

- [x] 2.1 `SchemaMapService::SCHEMA_MAPPING`, `SettingsLoadService` and
      `WipSyncNotifier`'s `objectType`.

## 3. The migration

- [x] 3.1 `RenameTimeEntrySchemaSlug`, modelled on the loyalty-account step,
      registered BEFORE `InitializeSettings`.
- [x] 3.2 Idempotent; refuses when both slugs exist or the old one is
      duplicated.

## 4. Verification

- [x] 4.1 Rename, no-op, both-slugs and duplicate cases.
- [x] 4.2 2,855 tests green.

## 5. Next

- [ ] 5.1 Move `hours`, `date`, `user` and `description` onto humaniq's
      `TimeEntry` so they live once, and make `BillingCategoryWidget` a
      `requiredApp: humaniq` widget that joins the two — the same pattern this
      app already uses to read planninq's `project`.
- [ ] 5.2 Migrate existing rows: one humaniq `TimeEntry` per billing line, and
      the line's `timeEntry` pointed at it.
