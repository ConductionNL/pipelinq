# Admin settings as declared sections

Delta over `specs/admin-settings`.

## ADDED Requirements

### Requirement: The admin forms are declared sections

The Lead Management, Shillinq integration, BI export, marketing intelligence and provision-data sections SHALL be CnSettingsFieldsSection declarations read from `src/views/settings/adminSections.json`. Each SHALL read `GET /api/settings` (`config`), save only the settings it changed with `PUT /api/settings`, check its fields before saving (a stale threshold of at least one whole day, export retention and warning hours of at least one, an email address in the right form), and say whether it saved. The page SHALL keep its own copy of the config in step with what a section saved.

#### Scenario: Changing the stale threshold

- GIVEN the stale threshold is 14 days
- WHEN the administrator sets it to 21 and saves
- THEN only `lead_stale_threshold_days: 21` is sent

@e2e exclude the save is the library's, covered by its CnSettingsFieldsSection specs; the declaration is asserted by tests/vitest/adminSections.spec.js.

### Requirement: The setup wizard opens from the admin shell

The admin page SHALL pass `showSetup` and the manifest's `setup.steps` to CnAdminSettingsShell, so an administrator can run the first-time setup again from the version card. It SHALL NOT read whether the wizard was closed before.

#### Scenario: Running the wizard again

- GIVEN an administrator on the admin page
- WHEN they press "Run setup wizard"
- THEN the wizard opens with the manifest's steps

@e2e exclude the button and wizard are the library shell's; the wiring is asserted by tests/vitest/setupWizardRerun.spec.js.

### Requirement: No admin surface is an in-app route

No admin settings or diagnostics component SHALL be mounted by the app's vue-router. The POS customer lookup settings (search fields, at least one; purchase history depth; consent sync; a customer for on-account sales) SHALL be a section on the admin page. The BRP monitor SHALL be a read-only section on the admin page that shows the last 24 hours of lookups (count, cache hits, errors, average response time, the period) and the mTLS certificate's state and expiry, or says nothing was reported yet and no certificate is configured. The page `/sync-settings` SHALL NOT exist.

#### Scenario: The certificate expires soon

- GIVEN the certificate health says `warning` with 20 days left
- THEN the BRP monitor section shows "The mTLS certificate expires on <date>, in 20 days. Replace it soon." as a warning

@e2e exclude a read-only declaration over a fixed endpoint, asserted by tests/vitest/adminSections.spec.js.

### Requirement: A delete on the admin page asks in a dialog

Deleting a skill or an agent profile SHALL ask in CnDeleteDialog, which names the row, and SHALL say in the dialog whether the delete worked. The page SHALL NOT use the browser's `confirm()`.

#### Scenario: Deleting a skill

- WHEN the administrator presses Delete on the skill "Parking"
- THEN a dialog asks to delete "Parking", and only its confirm button deletes it

@e2e exclude the dialog is CnIndexPage's built-in CnDeleteDialog on the declared lists; asserted by tests/vitest/adminLists.spec.js.

### Requirement: Settings with lists, rows or a JSON value are declared too

The menu structure (radio, and the simple menu's modules as checkboxes saved as comma text, shown while the structure is simple, saved on each change), the VAT rate per class (one key each inside the `vat_rates` JSON setting), the forecast configuration (groups picked from the server's groups, exchange rates as currency-to-rate rows that refuse a code that is not three letters and a rate not above zero), prospect discovery (provinces and legal forms as multi-selects, SBI codes and keywords one per line, a replace-only KVK key, on `/api/prospect-settings`), CTI and xWiki SHALL be declared sections. A connection test SHALL say the server's verdict: "Connection OK (<platform>)" or "Connection failed: <reason>" for CTI, "xWiki reachable at <address>" or why not for xWiki.

#### Scenario: Adding an exchange rate

- GIVEN the forecast section with the rate USD 0.92
- WHEN the administrator adds GBP 1.17 and saves
- THEN `exchange_rates` is saved as `{ "GBP": 1.17, "USD": 0.92 }`

@e2e exclude the row editor is the library's, covered by its CnSettingsFieldsSectionLists spec; the declaration is asserted by tests/vitest/adminSections.spec.js and tests/vitest/leadCurrency.spec.js.

#### Scenario: Prospect settings load

- GIVEN ICP settings were saved
- WHEN the administrator opens the admin page
- THEN the Prospect Discovery section shows them, read from `/api/prospect-settings`

@e2e exclude asserted against the route table by tests/vitest/adminSections.spec.js.

### Requirement: The admin lists are declared index pages

Product categories, skills, agent profiles, POS tender types, POS staff and POS roles SHALL be embedded CnIndexPage declarations in `src/views/settings/adminLists.js`. A row SHALL open in place: in its form dialog (`PosRoleFormDialog`, `PosStaffFormDialog`, `PosTenderTypeFormDialog`), which the admin page mounts through `cnOpenModal`, or in the built-in form dialog for register objects. Closing a dialog SHALL read the lists again. An agent profile's user SHALL be picked from the Nextcloud users, never typed, and its skills from the skills. A staff member's role SHALL show the role's name. Deleting a tender type SHALL ask in a dialog that names it and SHALL show the server's refusal.

#### Scenario: Adding an agent profile

- WHEN the administrator presses Add on Agent Profiles
- THEN a form opens with a user picker and a skills picker, and no browser prompt

@e2e exclude asserted by tests/vitest/adminLists.spec.js; the user picker is the library form's `format: user` field.

#### Scenario: Opening a POS role

- WHEN the administrator clicks a POS role
- THEN the role form opens in a dialog on the admin page, and nothing navigates

@e2e tests/e2e/spec-coverage/visual-coverage-admin-pos.spec.ts

### Requirement: The portal tenant is picked from the tenants

`GET /portal/api/admin/tenants` SHALL answer, for an administrator only, every tenant with a stored portal configuration once, by its display name (else its id), with the default tenant first whether or not it is stored. The portal section SHALL offer these in a select labelled "Tenant" instead of a free-text field; typing an id that is not listed SHALL start that tenant's configuration. Picking a tenant SHALL read its configuration, accounts and audit events.

#### Scenario: Switching to another municipality

- GIVEN tenants `default` and `zuid` ("Gemeente Zuid") are configured
- WHEN the administrator picks "Gemeente Zuid"
- THEN the section reads the configuration of `zuid`

@e2e exclude covered by tests/Unit/Controller/PortalAdminControllerTest.php and tests/vitest/portalAdminSettings.spec.js.

### Requirement: A vocabulary value in use cannot be removed

`DELETE /api/settings/lead-sources/{id}` and `DELETE /api/settings/request-channels/{id}` SHALL count the leads (`source`) or requests (`channel`, `ticketType: request`) that still hold the value, and while any do SHALL refuse with 409 and a translated sentence naming the value and the count ("Website is still used by 3 leads. Give them another source first."). The admin page SHALL ask in a dialog before removing and SHALL show that sentence; it SHALL NOT count the records itself.

#### Scenario: Removing a channel requests still use

- GIVEN 3 requests have channel "Email"
- WHEN the administrator removes "Email"
- THEN the server answers 409 and the dialog says "Email is still used by 3 requests. Give them another channel first."

@e2e exclude covered by tests/Unit/Controller/LeadSourceControllerTest.php, tests/Unit/Controller/RequestChannelControllerTest.php, tests/Unit/Service/VocabularyUsageTest.php and tests/vitest/leadSourceAndSubmitCopy.spec.js.

### Requirement: Marketing traffic is declared, with a way to remove the stored key

The marketing traffic section SHALL be a declared section: the campaign parameter switch, the Portaliq portal slug, the Search Console properties one per line (stored as comma text), the service account key as a replace-only secret, the service account address and the last import as notes read from `/api/marketing/search-queries`, and "Remove the stored key" while a key is stored, which clears it through the settings write and reads the section again.

#### Scenario: Removing the stored key

- GIVEN a Search Console key is stored
- WHEN the administrator presses "Remove the stored key"
- THEN `search.gsc.service_account_key_clear` is sent, the section says "The stored key was removed" and the button goes

@e2e exclude the action is the library's (CnSettingsFieldsSectionLists spec); the declaration is asserted by tests/vitest/adminSections.spec.js.
