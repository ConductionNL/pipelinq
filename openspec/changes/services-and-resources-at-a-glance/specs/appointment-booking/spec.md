# appointment-booking Specification (delta)

## Purpose

The people who set up booking see per service what it is booked on and which resources match, and see on each service and resource page how busy it is. Follows boards PqServices, PqService and PqResource. Covers row portal-booking-capacity (decision 99).

## ADDED Requirements

### Requirement: Services and resources share one page with tabs (REQ-SRG-001)

The app SHALL show services and resources on one page with the tabs Services and Resources, each carrying its count. The routes `/services` and `/resources` SHALL open that page on the matching tab. The services tab SHALL offer the views All, Bookable online, Draft and Archived with counts, and the columns Service (name with the description beneath), Duration, Buffer before / after, Price ("No price set" when empty), Online, Booked on (the required resource types) and Status, and SHALL show the footnote that a service is booked on the resources whose type and skills it asks for and that changing a duration clears the cached free slots.

#### Scenario: Opening resources from the menu
- GIVEN an administrator in the Set up the app menu
- WHEN they click Resources
- THEN the Services and resources page opens on the Resources tab at `/resources`

#### Scenario: Views with counts
- GIVEN seven services, five bookable online, one draft
- WHEN the services tab loads
- THEN the views read All 7, Bookable online 5, Draft 1, Archived 0

### Requirement: Each service shows the resources that match it (REQ-SRG-002)

The system SHALL compute, with the same eligibility rule the booking path uses, which active and bookable resources can do a service, and SHALL show per service in the list a line naming its required skills and resource types followed by the number of matching resources, and on the service page a list Resources that can do this with each resource's name, type and maximum concurrent bookings or skills. A service that no resource matches SHALL show "No resource matches" as a warning.

#### Scenario: A passport service with four matching resources
- GIVEN the service "Passport or ID card application" asks for skill civil affairs and a room
- AND four active, bookable resources meet that
- WHEN the services list loads
- THEN its line reads "Skill civil affairs, a desk · 4 resources match"

#### Scenario: Nothing matches
- GIVEN a draft service that asks for skill registrar and no resource has it
- WHEN the service page opens
- THEN Resources that can do this shows "No resource matches" as a warning

### Requirement: A service page shows how busy the service is (REQ-SRG-003)

The service page SHALL show a Bookings figure with the number of bookings for the service that start in the current week and the number of no-shows in the current month, and a list Appointments for this service with its upcoming bookings (date and time, customer, resource, status), each linking to its booking.

#### Scenario: Bookings this week
- GIVEN 38 bookings for "Passport application" this week and 4 no-shows this month
- WHEN the service page opens
- THEN the figure reads 38 with "this week, 4 no-shows this month"

### Requirement: A resource page shows how much of its bookable time is booked (REQ-SRG-004)

The resource page SHALL show a This week figure with the number of bookings assigned to the resource in the current week and the share of its bookable time they fill, where bookable time is its working hours this week minus its vacations. A resource with no bookable time this week SHALL show the count without a share.

#### Scenario: A desk clerk's week
- GIVEN Fatma Yildiz works 33 hours this week and her 22 bookings fill 24.4 of them
- WHEN her resource page opens
- THEN the figure reads 22 with "bookings, 74% of the bookable time"

#### Scenario: On holiday all week
- GIVEN a resource on vacation for the whole week
- WHEN its page opens
- THEN the figure shows the count and no percentage
