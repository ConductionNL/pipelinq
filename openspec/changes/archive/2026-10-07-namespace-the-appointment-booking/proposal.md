# Namespace the appointment booking

## Why

`booking` was claimed by this app and by shillinq. A schema slug is global per
organisation and `SchemaMapper::find()` matches `LOWER(slug)`, so whichever row
the lookup reached first answered for both.

shillinq's bookings subsystem is the larger claimant, so its slug stays bare.
This is the customer appointment: customer, service, resource assignments, time
window, confirmation and reminder stamps.

It completes the appointment set alongside `appointmentResource`.

## Renamed apart

The two share `status` and nothing else.

## The decoys

`booking` reads as ordinary English here, and most of its occurrences are not
the slug:

- roughly twenty log-context keys, `['booking' => $bookingId]`
- nine template-context reads in `AppointmentEmailService`,
  `$context['booking']['startAt']` and friends, which address the email
  template's own data bag
- a dashboard widget id, `"id": "booking-deposit"`

This is the rename I deliberately left out of the first namespacing pass,
because a blanket replace would have rewritten all of them.
