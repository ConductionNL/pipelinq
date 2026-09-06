# Namespace the appointment service

## Why

`service` was claimed by three apps: shillinq, stackiq and this one. A schema
slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so whichever row the lookup reached first answered for all three.

They share **`name`** and nothing else. Three different records that reached for
the same word, so all three namespace rather than fold. shillinq keeps the bare
slug; stackiq took `catalogService`; this is the bookable appointment service,
completing the set with `appointmentBooking` and `appointmentResource`.

stackiq created its half of this collision the same way shillinq created
`mandate`: `RenameDutchSchemaSlugs` renamed `dienst` to `service`, and the
target was already taken.

## The decoys

`service` is everywhere in this tree and almost none of it is the slug:

- a **DI container key**: `$context['service']->find(...)` in `ListObjectStore`
- **template context**: `$context['service']['name']` in `AppointmentEmailService`
- a **product type**: `typeOptions: ['product', 'service']`, and
  `$catalogue[$name] = 'service'` in the segment signals
- a **complaint category**: `ComplaintSlaService::VALID_CATEGORIES`
- a **GDPR processing intent**: `ComplianceService::INTENT_SERVICE`
- a **journey message type** in `JourneyFormView`
- an unrelated slug, `'service-desk-routing'` in the store gallery

One got through the first pass: a test fixture typing a demo product as
`'service'`. `SegmentSignalServiceTest::testOnlyCatalogueItemsAreClassified`
caught it and that file was reverted.
