# Tasks: clients-preferred-name

- [ ] 1.1 Fragment adding `preferredName` to `contact` and to `client` (shown for person clients)
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Write `NICKNAME` in `ContactVcardPropertyBuilder::buildProperties()` and read it in both `ContactDataBuilder` import builders
  - Verify: PHPUnit on both directions, including an empty value that clears `NICKNAME`
- [ ] 1.3 ClientDetail and ContactDetail header show the preferred name
  - Verify: Playwright `tests/e2e/preferred-name.spec.ts` sets Jan on contact Johannes Jansen and sees it on ContactDetail
- [ ] 1.4 CTI screen pop shows the preferred name first for a matched caller
  - Verify: Vitest on the screen pop component with and without a preferred name
- [ ] 1.5 `TemplateRenderer` variable `preferredName` with the full name fallback
  - Verify: PHPUnit on `render()` with and without the value
- [ ] 1.6 English strings with Dutch translation ("Roepnaam"), user doc section in `docs/Features/client-management.md`
  - Verify: `npm run test:l10n` exit 0; docs build exit 0
