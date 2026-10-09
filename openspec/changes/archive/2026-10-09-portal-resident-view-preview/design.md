# Design: portal-resident-view-preview

## Context (read at pipelinq development cfe0a0a51, nextcloud-vue 2.57.1)

- **Two surfaces show tickets outside the back office, and they differ.**
  - The bespoke portal (`/apps/pipelinq/portal`) serves residents with a
    portal account. `lib/Service/Portal/PortalRequestService.php`
    `presentSummary()` (:474) returns id, number (`caseReference` or
    `reference`), subject (`title`), category, status and date (`occurredAt`).
    `presentDetail()` (:494) adds `description`, the conversation from
    `customerNotes()` (:519: `customerMessage` and `portalReplies`, never
    `notes`), `canReply`, and the assignee only when the tenant sets
    `exposeAssigneeName` (read in `lib/Controller/PortalRequestController.php:116`).
    It serves request tickets only (`TICKET_TYPE = 'request'`, :70). The matrix
    cites :444-519 for these methods; at this sha they sit at :474-519.
  - portaliq serves the `client` audience (an organisation's contacts)
    through `lib/Portal/PortalContributionProvider.php`. Its `clientRequests`
    collection filters `ticketType: request` and whitelists `title`,
    `category`, `status`, `description` and `occurredAt` (:170, fields at :189). It
    carries neither `customerMessage` nor a number. The `customer` audience
    (B2C) gets no ticket collection at all.
- **So the one marker the back office has is only half true.** The ticket's
  `customerMessage` property (`lib/Settings/register.d/99-unify-ticket-supertype.json`)
  says "Shown to the customer on this request in the customer portal". That
  holds for the bespoke portal and not for portaliq.
- **TicketDetail.** `src/manifest.json:2232` declares it with a data widget,
  Related, Besluitvorming, and one body widget, `RoutingSuggestionSection`,
  registered in `src/registry.js:806` as `kind: 'section'`.
- **Routes.** pipelinq has no ticket controller; the back office reads tickets
  from OpenRegister. `appinfo/routes.php:331` onward holds the public portal
  routes.
- **portaliq's app id** is `portaliq` (`appinfo/info.xml` on portaliq
  development).

## Decisions

### D1. The preview is built by the portal's own code

The preview must not restate the portal's rules. `PortalRequestService` gets a
public `previewDetail(array $ticket, bool $exposeAssigneeName): array` that
returns `presentDetail()` unchanged. The portaliq panel is built from
pipelinq's own `PortalContributionProvider`: the controller takes the `client`
contribution, finds the collection whose `filter.ticketType` matches the
ticket, and keeps only its `fields` plus the identifiers. Both panels change
the moment the portal code changes.

### D2. One read endpoint, the caller's own rights

`GET /api/tickets/{id}/resident-view` (`#[NoAdminRequired]`) reads the ticket
through OpenRegister as the calling user, so a handler who may not read the
ticket gets 404. It answers `{bespoke: {...}, portaliq: {...}|null,
internalFields: [...]}`. `portaliq` is null unless the app is installed and
the ticket has a `client`. `internalFields` lists the ticket properties with a
value that neither panel shows.

### D3. The handler's name follows the tenant setting

The bespoke panel uses the default tenant's `exposeAssigneeName`. With more
than one tenant, the panel shows the name with the line "Shown only on portals
that show the handler's name", because a ticket carries no tenant.

### D4. portaliq carries the message to the customer

`customerMessage` joins the `clientRequests` fields, so the field label is true
on both portals. It is the one field whose purpose is to reach the customer; a
handler who writes it for an organisation's contact expects it to arrive.

### D5. A section on request tickets only

`ResidentViewSection` mounts as a body widget on TicketDetail and renders
nothing for complaint and interaction tickets. It reads the endpoint once per
page load and on the page refresh signal, so a saved message to the customer
shows at once.

## Risks

- The endpoint is a new door onto a ticket. It adds no data the caller cannot
  already read, and the hydra IDOR gate checks the per-object read.
- A later portal change that bypasses `presentDetail()` would make the preview
  wrong. The unit test in task 1.1 asserts that the portal's detail response
  and the preview are equal for the same ticket.
