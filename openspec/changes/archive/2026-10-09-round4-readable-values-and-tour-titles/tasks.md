# Tasks: round4-readable-values-and-tour-titles

## 1. Tour step titles

- [x] 1.1 `src/menu-layout.simple.json`: a title for steps 2 to 7 of `pipelinq:contact-centre`
- [x] 1.2 `src/manifest.json`: a title for steps 2 to 11 of `pipelinq:getting-started`
- [x] 1.3 en and nl catalogue entries for every new title
- [x] 1.4 `tests/vitest/tourStepTitles.spec.js`: every step of every tour in the manifest, its fragments and both menu layouts has a title with en and nl entries
  - Verify: fails on development for both tours

## 2. Readable values

- [x] 2.1 `lib/Settings/register.d/99-zz-readable-values.json`: `x-enum-labels` for every enum on client, lead, appointmentService (also the step resource type) and appointmentResource
- [x] 2.2 `src/utils/enumLabels.js`: the label maps for the forms and the service page
- [x] 2.3 ClientForm (type), LeadForm (priority), ServiceForm (cancellation policy), ServiceStepsEditor and ServiceDetail (cancellation policy, step resource type) show labels
- [x] 2.4 en and nl catalogue entries for every new label
- [x] 2.5 `tests/vitest/readableValues.spec.js`: every enum on the four schemas is labelled in en and nl, the JS maps equal the register, and the components no longer print the code
  - Verify: fails on development

## 3. Add buttons in the interface language

- [x] 3.1 `src/manifest.json`: "Add contact person" and "New request" as the client page's Add labels
- [x] 3.2 `src/utils/widgetAddLabels.js` translates every object-list `addLabel`; `src/main.js` runs it on the merged manifest
- [x] 3.3 en "Add contact person", nl "Contactpersoon toevoegen"
- [x] 3.4 Tests in `tests/vitest/readableValues.spec.js`

## 4. Credentials text

- [x] 4.1 Confirmed the text is `CnCredentials` in @conduction/nextcloud-vue; left to lane R4-LIB
