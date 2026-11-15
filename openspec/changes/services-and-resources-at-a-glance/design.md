# Design: services-and-resources-at-a-glance

Read at pipelinq development `c9c6a728d` (8 Oct 2026).

## Screens

Canvas part 1 (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a; local copy `zuiddrecht/v2/project/`).

### PqServices: services and resources

| Where | What it shows | Source |
|---|---|---|
| Header | Services and resources, Download, New service, "Showing 7 of 7 services · what residents can book, and who or what it is booked on" | list total |
| Tabs | Services 7, Resources 7 | counts of `appointmentService`, `appointmentResource` |
| Views | All 7, Bookable online 5, Draft 1, Archived 0 | `bookableOnline`, `status` |
| Modes | Table, Cards, Board, Map | shared index page |
| Columns | Service (name, description), Duration, Buffer before / after, Price ("No price set" when empty), Online (Yes/No), Booked on (Staff, Room, Equipment pills), Status | `name`, `description`, `durationMinutes`, `bufferBeforeMinutes`/`bufferAfterMinutes`, `price`/`currency`, `bookableOnline`, `requiredResourceTypes`, `status` |
| Match line | "Skill civil affairs, a desk · 4 resources match" | `requiredSkills`, `requiredResourceTypes`, count of matching active, bookable resources |
| Footnote | "A service is booked on the resources whose type and skills it asks for. Changing a duration clears the cached free slots." | static text |
| Menu | Set up the app: Pipelines, Services, Resources, Integrations, BI export, Modules | Services and Resources open the same page on their tab |

### PqService: one service

Header "Passport application", Edit, Delete, pill Active, breadcrumb Services, line "20 minutes · EUR 81.40 · bookable online". Cards Service information, Policies, Required skills and Multi-step composition with Edit steps (all built). New on this change:

| Where | What it shows | Source |
|---|---|---|
| Bookings | 38, "this week, 4 no-shows this month" | count of bookings for the service starting this ISO week; count with `status: no-show` this month |
| Appointments for this service | upcoming bookings | `appointmentBooking` with `serviceId`, `startAt` from now |
| Resources that can do this | "Desk 1, stadskantoor · Room · max 1 at a time", "Fatma Yildiz · Staff · civil affairs" | resources matching the service by the routing rule |

### PqResource: one resource

Header "Fatma Yildiz", Edit, Delete, pills Staff and Active, line "desk staff · civil affairs". Resource information, Working hours and Vacations are built, with the line "No bookings are offered in these windows." New:

| Where | What it shows | Source |
|---|---|---|
| This week | 22, "bookings, 74% of her bookable time" | bookings with this resource in `resourceAssignments` this week; booked minutes over bookable minutes (working hours minus vacations) this week |

## Decisions

- **D1. One matching rule.** The match line and Resources that can do this call the same eligibility code the booking path uses (REQ-APT-004, `lib/Service/EligibilityService.php:100` `getEligibleResources()`), exposed through a read-only endpoint `GET /api/appointment-services/{id}/eligible-resources`. A second copy of the rule in the browser would drift.
- **D2. Figures from OpenRegister.** Counts are OpenRegister list queries with `_count` and facets (ADR-022). The utilisation share sums booked minutes from the week's bookings against bookable minutes from working hours and vacations, computed in one backend call so the resource page does one request.
- **D3. One page, two routes.** `/services` and `/resources` both render the Services and resources page, on the matching tab, so existing links keep working.
- **D4. Pronouns.** The board writes "her bookable time"; the app writes "of the bookable time" so it never guesses a person's pronoun.
