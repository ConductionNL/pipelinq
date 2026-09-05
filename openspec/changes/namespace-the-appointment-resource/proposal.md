# Namespace the appointment resource

## Why

`resource` was claimed by this app and by shillinq. A schema slug is global per
organisation and `SchemaMapper::find()` matches `LOWER(slug)`, so whichever row
the lookup reached first answered for both.

shillinq's bookings subsystem is the larger claimant, so its slug stays bare.

## Renamed apart

The two share `name`, `status` and `type`. None of those identifies the record;
they are the attributes almost any catalogue-ish schema carries. This app's is
the room, chair or person a customer books time with, reached from
`AvailabilityService` and the appointment calendar.

## The decoys

`resource` is an ordinary English word, so most of its occurrences here are not
the slug:

- **Log-context keys.** `['resource' => $resourceId, 'date' => $date]` appears
  fourteen times across `AvailabilityService`, `BookingService`,
  `AppointmentCalendarLeafProvider`, `WalkInQueueService` and
  `AvailabilityCacheRefreshJob`.
- **A ZGW notification field.** `$notification['resource']` in
  `NrcNotificationListener` is part of the NRC contract and names a ZGW resource
  type, not a schema.

The second one bit: a blanket rename in the test tree rewrote the NRC fixtures
and turned two listener tests red. Reverted; they were never slug uses.
