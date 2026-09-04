## 1. Schemas and seed data

- [ ] 1.1 Add `lib/Settings/register.d/96-marketing-lists-and-double-opt-in.json` with the `mailingList` and `subscription` schemas, the `subscription` `x-openregister-lifecycle` on `state`, and both slugs listed on the `pipelinq` register; verify `python3 -m json.tool` parses it and `npm run check:manifest` exits 0.
- [ ] 1.2 Extend `consentRecord` in the same fragment with `listId`, `evidence` and the `soft-opt-in` lawful basis, restating the whole `lawfulBasis` enum because a fragment list replaces rather than merges; verify the merged enum still carries `consent`, `legitimate-interest` and `contract`.
- [ ] 1.3 Seed two mailing lists and a realistic subscription mix on the existing demo contacts, including one pending, one unsubscribed and one soft opt-in; verify every seeded `contactId` matches a contact slug already used by the `consentRecord` seeds.
- [ ] 1.4 Add every new schema title, property title and enum label to `l10n/en.json` and `l10n/nl.json`, then run `npm run l10n:build`; verify `npm run check:schema-l10n` exits 0 without moving the baseline.

## 2. Token and service layer

- [ ] 2.1 Add `lib/Service/ListTokenService.php` signing and verifying purpose-scoped HMAC tokens for confirm, unsubscribe and preferences, with a per-instance key minted into app config on first use; verify the round-trip, the tampered-payload rejection and the expiry rejection in unit tests.
- [ ] 2.2 Add `lib/Service/MailingListService.php` with list CRUD through `ObjectService` carrying the `_rbac` and `_multitenancy` flags, a public projection, and per-state subscription counts; verify the counts against a fixture holding one subscription in each state.
- [ ] 2.3 Add `lib/Service/SubscriptionService.php` with subscribe, confirm, unsubscribe by token, unsubscribe by list, global unsubscribe, the soft opt-in import and the preference read and save, mailing the signed confirmation link through `IMailer` and leaving the subscription pending when the send fails; verify the state machine refuses every transition the lifecycle does not declare and the mailer is called once per subscribe and never on a honeypot submission.
- [ ] 2.4 Give `ComplianceService` list-scoped consent: a nullable `listId` on `findConsentRecord`, `hasConsentForList`, `recordListConsent`, a `listId` on `recordConsentWithdrawal`, and the `soft-opt-in` basis that fails without its evidence; verify the existing channel-wide tests still pass unchanged.
- [ ] 2.5 Teach `BlastService` to resolve a `listId` audience through `SubscriptionService`, refusing a blast that names neither a segment nor a list; verify only confirmed subscribers are queued and the no-audience guard returns its documented status.

## 3. HTTP surface

- [ ] 3.1 Add `lib/Controller/ListPublicController.php` with the subscribe, confirm, unsubscribe and preference endpoints as `PublicPage`, rate limited, brute-force protected and answering uniformly; verify each rejected token registers an attempt.
- [ ] 3.2 Add `lib/Controller/MailingListController.php` and `lib/Controller/SubscriptionController.php` following the `SegmentController` conventions, taking identity from `IUserSession` and guarding every object with the owner access policy; verify an unprivileged session is refused.
- [ ] 3.3 Register all routes in `appinfo/routes.php` with the literal-prefixed public paths ahead of the parameterised ones; verify the route-auth and route-reachability gates exit 0.

## 4. Interface

- [ ] 4.1 Add `src/manifest.d/76-marketing-lists.json` with the Lists menu entry, a declarative index over `mailingList` and a detail page carrying the subscriptions section; verify `npm run check:manifest` exits 0.
- [ ] 4.2 Add `src/services/subscriptionState.js` mapping a state to its chip vocabulary, reducing rows to per-state counts and building the embed snippet, then the subscriptions section component and its modal in their own files, registered as `kind: 'section'` and bound to a list or a contact; verify the vitest spec covers every state and the unknown-state fallback, the modal lives in `src/modals/` and the modal-isolation gate exits 0.
- [ ] 4.3 Add the mailing list embed admin section to the settings page; verify the snippet renders the instance URL and the selected list id and that no placeholder looks like a secret.
- [ ] 4.4 Extend the blast wizard's audience step so a list is selectable alongside a segment; verify the step refuses to advance with neither chosen.

## 5. Tests and documentation

- [ ] 5.1 Add PHPUnit coverage for the token round trip, the subscription state machine, the soft opt-in ground, the global unsubscribe and the rule that a pending subscription is never queued; verify `composer test` exits 0.
- [ ] 5.2 Add `tests/e2e/spec-coverage/marketing-lists.spec.ts` covering the Lists page, the signup to confirm to unsubscribe path over HTTP from the authenticated context, and the preference centre, with an `@e2e` annotation per scenario; verify `npm run lint` exits 0.
- [ ] 5.3 Add `docs/user/mailing-lists.md` describing subscribe, confirm, unsubscribe and preferences from the marketer's and the subscriber's side; verify it carries the SPDX header and no em-dash.
- [ ] 5.4 Run the full gate set: `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links`, `npm run check:schema-l10n` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`; verify each exits 0.
