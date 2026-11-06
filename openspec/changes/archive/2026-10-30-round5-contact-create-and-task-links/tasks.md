# Tasks: round5-contact-create-and-task-links

## 1. Add a contact person on a client page

- [x] 1.1 `src/components/widgets/ContactAwareObjectListWidget.js`: extends CnObjectListWidget; a create for `client` or `contact` goes through `createWithContact`, with the list filter as defaults; a failure shows the backend message
- [x] 1.2 `src/registry.js`: register it as the widget type `ContactAwareObjectList`
- [x] 1.3 `src/manifest.json`: `client-quick-contacts` uses `ContactAwareObjectList`; `lead-contacts` gets `allowCreate: false`, as its note says
- [x] 1.4 `tests/vitest/round5ContactCreateAndTaskLinks.spec.js`: the tab uses the widget, no client or contact object-list offers the library create, the create posts to the contact-first path linked to the client, a failure never reports success
  - Verify: the manifest tests fail on the old manifest and registry

## 2. Task notification link

- [x] 2.1 `src/manifest.json`: deep link for `crmTask` to `/apps/pipelinq/tasks/{uuid}`
- [x] 2.2 Spec: every schema that sends a notification and has a detail page has a deep link to that page
  - Verify: fails on the old manifest (crmTask missing)

## 3. Task name

- [x] 3.1 `lib/Settings/register.d/97-task-name.json`: `crmTask.configuration.objectNameField = subject`
- [x] 3.2 Spec: the merged register names a task after its subject and keeps the lifecycle
  - Verify: fails without the fragment

## 4. Verification

- [x] 4.1 Full checks once: check:strict, vitest, lint, format, test:l10n
- [x] 4.2 Live on :8099: add a contact person on a client, the task notification link, the task heading and activity text
