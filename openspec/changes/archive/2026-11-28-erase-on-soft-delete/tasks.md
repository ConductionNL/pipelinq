# Tasks: erase-on-soft-delete

- [x] 1.1 Tests first: a soft delete erases, the purge after it changes nothing more, an edit, a restore and an edit inside the trash erase nothing.
  - files: `tests/Unit/Listener/ContactErasedOnSoftDeleteTest.php`, `tests/Stubs/Db/ObjectEntity.php`
  - test: `vendor/bin/phpunit --no-coverage --filter ContactErasedOnSoftDeleteTest`
- [x] 1.2 `ContactErasedListener` erases on the update that moves a contact into the trash, registered for `ObjectUpdatedEvent`.
  - spec_ref: `specs/consent-in-integriq/spec.md#requirement-a-soft-delete-erases-the-contact-in-integriq-req-cii-008`
  - files: `lib/Listener/ContactErasedListener.php`, `lib/AppInfo/Application.php`
- [x] 1.3 Live: soft delete a contact through the OpenRegister API, the integriq row loses its link and evidence and keeps the opt-out; purge it, nothing more changes.
  - live 2026-11-28 (sandbox NC 34.0 + MariaDB, OpenRegister main, integriq and pipelinq development): opted-out email row with contact ref + evidence and one pipelinq `messagingConsentRecord`; `DELETE /api/objects/28/138/{id}` 204 -> integriq row `opted-out`, contact_ref empty, evidence NULL, consent records 0; `DELETE /api/deleted/{id}?force=true` 200 -> contact row gone, integriq row identical (same updated_at).
