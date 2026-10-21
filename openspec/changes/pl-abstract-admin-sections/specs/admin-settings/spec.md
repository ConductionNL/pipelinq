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

No admin settings or diagnostics component SHALL be mounted by the app's vue-router. The BRP monitor SHALL be a read-only section on the admin page that shows the last 24 hours of lookups (count, cache hits, errors, average response time, the period) and the mTLS certificate's state and expiry, or says nothing was reported yet and no certificate is configured. The page `/sync-settings` SHALL NOT exist.

#### Scenario: The certificate expires soon

- GIVEN the certificate health says `warning` with 20 days left
- THEN the BRP monitor section shows "The mTLS certificate expires on <date>, in 20 days. Replace it soon." as a warning

@e2e exclude a read-only declaration over a fixed endpoint, asserted by tests/vitest/adminSections.spec.js.

### Requirement: A delete on the admin page asks in a dialog

Deleting a skill or an agent profile SHALL ask in CnDeleteDialog, which names the row, and SHALL say in the dialog whether the delete worked. The page SHALL NOT use the browser's `confirm()`.

#### Scenario: Deleting a skill

- WHEN the administrator presses Delete on the skill "Parking"
- THEN a dialog asks to delete "Parking", and only its confirm button deletes it

@e2e exclude covered by tests/vitest/adminDeleteDialog.spec.js.
