# Admin settings as declared sections

## Why

The Pipelinq admin page carried a hand-built form per section: its own load, save, dirty state, validation and notice, on raw inputs. nextcloud-vue's CnSettingsFieldsSection does all of that from a list of fields, and CnAdminSettingsShell already offers "Run setup wizard". The PL-ABSTRACT audit (21 Oct 2026, accepted by Ruben) also found admin screens nothing could reach, and a stub page that showed success without saving.

## What changes

- The Lead Management, Shillinq, BI export, marketing intelligence and provision-data forms become CnSettingsFieldsSection declarations in `src/views/settings/adminSections.json`. Each saves only its own changed settings, so a section no longer writes the whole config back.
- "Run the setup wizard again" is the shell's own `showSetup` action with the manifest's setup steps.
- The BRP monitor (lookups over the last 24 hours and the mTLS certificate) is a read-only section on the admin page instead of the unlinked in-app route `/admin/brp-monitor` (audit bug 13, ADR-004). It goes again with `brp-monitor-moves-to-integriq`.
- The sync settings page `/sync-settings` and its duplicate section are deleted: they saved nothing and showed success (audit bug 12), and a per-user settings page is not allowed. Mail and calendar sync come back only as a user settings dialog section, if still wanted.
- The menu structure, VAT rates, forecast, prospect discovery, CTI and xWiki forms become declarations too, on the library's multi-choice (`checkboxes`, `select` with `multiple`), key-to-value (`pairs`), JSON key (`path`) fields and action texts (nextcloud-vue `settings-fields-lists`).
- The POS customer lookup settings are a section on the admin page instead of the unlinked in-app route `/admin/pos-customer-link` (audit bug 13, ADR-004).
- Prospect discovery read and saved `/api/prospects/settings`, which no route serves, so its settings never loaded or saved. The section uses `/api/prospect-settings`.
- xWiki's reachability is no longer probed on every page load and shown next to Detected integrations: the connection is shown once there, and "Test connection" says whether xWiki answers.
- Product categories, skills, agent profiles and the POS tender types, staff and roles become embedded CnIndexPage declarations (`adminLists.js`). The admin page provides `cnOpenModal` and mounts the forms a row or Add opens. An agent profile's user is picked, not typed into a browser prompt. Needs the nextcloud-vue release with `index-page-embedded`.
- The unreferenced registry key `PipelineManagerView` goes, with its note about drag-and-drop reordering that never existed.
- Deleting a skill or an agent profile asks in CnDeleteDialog instead of the browser's `confirm()`.

## Impact

- Deleted: `MenuStructureSettings.vue`, `VatRatesSettings.vue`, `ProspectSettings.vue`, `CtiSettings.vue`, `components/admin/ForecastSettings.vue`, `views/admin/PosCustomerSettings.vue`, `services/menuStructureSetting.js`, the form-side rate helpers in `services/leadCurrency.js`; the manifest page `PosCustomerSettings` and registry key `PosCustomerSettingsView`.
- Needs the nextcloud-vue release with `settings-fields-lists`.
- Deleted: `ExportConfigurationSettings.vue`, `MarketingIntelSettings.vue`, `ProvisionDataSection.vue`, `SetupWizardSection.vue`, `views/admin/BrpMonitor.vue`, `views/sync/SyncSettings.vue`, `components/sync/SyncSettingsSection.vue`; the manifest pages `BrpMonitor` and `SyncSettings`; the registry keys `BrpMonitorView`, `SyncSettingsView`, `PipelineManagerView`.
- No endpoint or stored setting changes.
