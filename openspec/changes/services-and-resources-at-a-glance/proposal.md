---
kind: code
---

# Proposal: services-and-resources-at-a-glance

## Summary

The services list shows, per service, what it is booked on and how many resources match it, and the list and the resources sit on one page with two tabs. A service page shows its bookings this week, its no-shows this month, its upcoming appointments and the resources that can do it. A resource page shows its bookings this week and how much of its bookable time they fill. This follows boards PqServices, PqService and PqResource.

## The rows this covers

Source: pipelinq `openspec/parity/capabilities.json`. Decision 99 (8 Oct 2026): boards that draw a capability no row has get a row, and what is unbuilt gets a spec.

- **portal-booking-capacity** See per service which resources can do it and how busy each service and resource is (new, specified, partial).
- **portal-booking-services** and **portal-booking-resources** (new, built) are the set-up rows for the same boards. They link the main spec `appointment-booking`; this change adds the board details their pages still miss.

## Why

Read on development `c9c6a728d`:

- `src/manifest.d/80-appointment-booking-admin.json` declares Services (`/services`, columns name, durationMinutes, price, bookableOnline, status) and Resources (`/resources`) as two index pages. The board puts them on one page with tabs Services 7 and Resources 7, and adds columns Buffer before / after, Booked on (resource types) and a line per service naming its requirement and how many resources match, plus views All, Bookable online, Draft and Archived with counts.
- `src/views/bookings/ServiceDetail.vue` shows Service information, Policies and the Multi-step composition (built). It has no Bookings figure, no list of appointments for the service and no list of resources that can do it.
- `src/views/bookings/ResourceDetail.vue` shows Resource information, Working hours and Vacations (built). It has no figure for this week's bookings or the share of bookable time they fill.

The matching rule exists: `REQ-APT-004 Skill-Based Routing` in `openspec/specs/appointment-booking/spec.md` picks the resources whose type and skills a service asks for. Nothing shows the result to the person setting up the service.

## What changes

1. One page Services and resources with tabs, both lists keep their own routes for deep links.
2. Services list: the board's columns, views with counts, and the matching-resource line computed with the routing rule.
3. Service page: a Bookings figure (this week, no-shows this month), Appointments for this service (upcoming bookings) and Resources that can do this.
4. Resource page: a This week figure (bookings, share of bookable time) from working hours, vacations and bookings.
5. The footnote "A service is booked on the resources whose type and skills it asks for. Changing a duration clears the cached free slots." under the services list.

## Out of scope

- Changing the routing rule or the availability computation.
- Utilisation reports over longer periods (reporting area).
