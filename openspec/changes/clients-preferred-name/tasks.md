# Tasks: clients-preferred-name

- [x] 1.1 Fragment adding `preferredName` to `contact` and to `client` (shown for person clients)
  - Built: `lib/Settings/register.d/19-preferred-name.json` (both with `slug`), `appinfo/info.xml` bumped so the import runs; label and help in six locales. Test: `tests/Unit/Settings/PreferredNameSchemaTest.php` validates what the Contacts sync writes (a name, and the empty text that clears it) against the merged schema, with a refusing control.
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 Write `NICKNAME` in `ContactVcardPropertyBuilder::buildProperties()` and read it in both `ContactDataBuilder` import builders
  - Verify: PHPUnit on both directions, including an empty value that clears `NICKNAME`
  - Built: `ContactVcardPropertyBuilder::addPreferredName()` and `ContactDataBuilder::withPreferredName()`. The key is written whenever the object (or the card) carries the field, empty included, so clearing works both ways; an organisation client carries none. Tests: `ContactVcardPropertyBuilderTest`, `ContactDataBuilderTest`.
- [ ] 1.3 ClientDetail and ContactDetail header show the preferred name
  - Verify: Playwright `tests/e2e/preferred-name.spec.ts` sets Jan on contact Johannes Jansen and sees it on ContactDetail
- [ ] 1.4 CTI screen pop shows the preferred name first for a matched caller
  - Verify: Vitest on the screen pop component with and without a preferred name
- [ ] 1.5 `TemplateRenderer` variable `preferredName` with the full name fallback
  - Verify: PHPUnit on `render()` with and without the value
- [ ] 1.6 English strings with Dutch translation ("Roepnaam"), user doc section in `docs/Features/client-management.md`
  - Verify: `npm run test:l10n` exit 0; docs build exit 0
