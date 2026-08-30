# Appointment Booking — Walk-In Queue (Member 09) Delta Spec

## Purpose

Support walk-in arrivals (WalkInTicket) for businesses that mix scheduled and
unscheduled service, with a real-time queue panel and rebalance-on-completion.

---

## ADDED Requirements

### Requirement: REQ-APT-012 Walk-In Queue

The system MUST support walk-in arrivals (WalkInTicket) for businesses that mix
scheduled and unscheduled service.

**Feature tier**: V1

#### Scenario: Walk-in ticket created on arrival

- **GIVEN** a customer walks into a barbershop without an appointment
- **WHEN** staff creates a WalkInTicket with `serviceId: (haircut)`, `displayName: "Mr. Jansen"`
- **THEN** a WalkInTicket MUST be created with `status: "waiting"`, `arrivedAt: now`, and `estimatedReadyAt` computed from gaps in the current schedule

#### Scenario: Queue rebalances as appointments complete

- **GIVEN** the queue has 3 waiting customers
- **WHEN** a staff member completes an appointment (Booking status → completed)
- **THEN** the WalkInQueueRebalanceJob MUST recalculate `estimatedReadyAt` for all waiting tickets

#### Scenario: Staff calls next customer from queue

- **GIVEN** the WalkInQueuePanel is displayed with a "Call next" button
- **WHEN** staff clicks "Call next"
- **THEN** the first waiting WalkInTicket MUST transition to `status: "called"`

#### Scenario: Serving and abandoning tickets

- **GIVEN** a called WalkInTicket
- **WHEN** staff clicks "Serve" (or "Abandon")
- **THEN** the ticket MUST transition to `served` with `actualServedAt` set (or `abandoned`)
