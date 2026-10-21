---
kind: code
---

# Proposal: pl2-booking-header-actions

## Summary

The booking page drew its six admin actions with a pipelinq component (BookingHeaderActions) and two dialogs, because
a declared action could not gate on "more than an hour from now" or follow the new booking a reschedule makes. Both are
now in nextcloud-vue (`@now+1h`, `onSuccessRoute.idPath` on an endpoint form), so the actions are declared header
actions, one primary per state (PL-ABSTRACT Q9): Confirm deposit while the deposit is pending, Mark completed once a
confirmed booking has ended. Mark no-show, Reschedule, Send reminder and Cancel booking are in the Actions menu while their
time window holds.

## Dependency

nextcloud-vue `feature/pl-abstract-pl2-actions` (the `@now±N` token, endpoint forms that follow the answer and require a
field, endpoint open-forms without a create schema).
