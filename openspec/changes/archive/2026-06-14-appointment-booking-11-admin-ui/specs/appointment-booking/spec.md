# Appointment Booking — Admin UI (Member 11) Delta Spec

## Purpose

Provide staff admin views for Services, Resources, and Bookings, plus a customer
timeline of bookings on the Customer detail page.

---

## ADDED Requirements

### Requirement: REQ-APT-014 Customer Timeline Integration

The system MUST display all Bookings on the Customer detail page, showing past and
future appointments with status, service, resource, and time.

**Feature tier**: V1

#### Scenario: Bookings visible on customer detail

- **GIVEN** a Customer has 5 past and 2 future Bookings
- **WHEN** an agent opens the Customer detail page
- **THEN** a "Bookings" section MUST display all 7 bookings chronologically with service name, resource name, date/time, status badge, and a link to the Booking detail

#### Scenario: Bookings section shows empty state if none exist

- **GIVEN** a Customer with no Bookings
- **WHEN** the Customer detail page is loaded
- **THEN** the Bookings section MUST display an empty state message (not an error)

### Requirement: REQ-APT-015 Admin Booking Management

The system MUST provide admin views for staff to manage Services, Resources, and
Bookings: list, create, edit, and delete.

**Feature tier**: V1

#### Scenario: Service list shows all services with filters

- **GIVEN** the admin opens the Services list page
- **WHEN** the page loads
- **THEN** a `CnIndexPage` list MUST display all Services with columns for name, duration, price, and status, with filters by status and bookableOnline

#### Scenario: Service detail allows editing all fields

- **GIVEN** an admin opens a Service detail page in edit mode
- **WHEN** they modify name, price, or multiStep configuration
- **THEN** changes MUST be saved to OpenRegister and the AvailabilityCache MUST be invalidated for all Resources using that Service

#### Scenario: Booking detail shows status actions

- **GIVEN** an admin opens a confirmed Booking detail page in the future
- **WHEN** the page renders
- **THEN** buttons for "Mark completed", "Mark no-show", "Reschedule", and "Cancel" MUST be available, wired to the booking endpoints
