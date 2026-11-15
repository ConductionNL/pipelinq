# Proposal: nav-ia-cleanup

## Problem

Pipelinq's navigation had accumulated entries that describe how the app was built
rather than what an operator does with it.

**Administrator configuration sat in the operator's navigation.** Messaging
(WhatsApp/SMS providers, send budgets, HSM templates), CTI telephony, the POS
payment providers, POS tender types, POS staff and POS roles are all things an
administrator sets up once. They were app pages — some top-level, some in the
in-app gear foldout — so every operator saw them in the same list as the tickets
and leads they actually work.

**Two entries read as the same thing.** "Betalingsmethoden" and "POS
betaalmethoden" sat next to each other under Point of Sale. They are not
duplicates — one is the payment *provider* (the PSP that processes the money:
Mollie, CCV, Adyen, Stripe), the other is the *tender type* (how a customer pays
at the till: cash, pin, voucher, and the GL account it posts to) — but nothing in
the labels said so, so the nav looked like it listed payment methods twice.

**Groups that carried nothing.** "Reports & Compliance" held exactly one page
(Billing categories), and the pairing of reporting with compliance never described
a real domain. "Product catalog" wrapped a single Products page.

**Surfaces that duplicated a search.** "Barcode lookup" was a page whose entire
job was to resolve one barcode to one product — which the Products index search
already does.

**A menu entry that was really a link into another app.** "AVG-verzoeken" was a
bare deep-link into OpenRegister's DSAR engine, which owns that subsystem
(ADR-047 Phase 3); pipelinq only contributes evidence to it.

## Solution

Move the configuration to where configuration belongs, delete what carried
nothing, and name the two payment surfaces so they stop reading as one.

1. **Admin configuration moves to the Nextcloud admin page**
   (`/settings/admin/pipelinq`) — not the in-app gear foldout, which is still the
   app's own shell. Six surfaces move: Messaging, CTI telephony, Payment providers
   (PSP), POS tender types, POS staff, POS roles. This is where an administrator
   already goes to configure an app, and where Nextcloud's own admin delegation
   applies.

2. **Rename the payment pair** to say which is which: *Payment providers (PSP)*
   and *POS tender types*, each describing itself in its section description.

3. **Drop what carried nothing**: the Reports & Compliance group and its Billing
   categories pages; the Product catalog group (Products becomes top-level);
   Barcode lookup; AVG-verzoeken.

4. **Messaging is a marketing concept, not a peer of it.** Once its configuration
   moves to the admin page there is no operator messaging surface left to place,
   so Marketing keeps Blasts and Blast performance, and messaging is what those
   send through rather than a top-level sibling.

## Scope

**In scope:** the app navigation (`src/manifest.json`, `src/manifest.d/*`,
`src/menu-layout.json`), the admin settings page (`src/views/settings/Settings.vue`),
the POS list/form views that must stop routing, and the registry entries for the
pages that no longer exist.

**Out of scope — deliberately kept:**

- The `billingCategory` **schema and its objects**. Only the nav entry and its
  three pages go. `ShillinqWipService` reads billing categories, and the "Hours by
  billing category" widget still charts them on the Operational dashboard.
- The **barcode data**. `product.barcode` stays; the Products index search already
  matches it (verified: searching `8714100838623` returns exactly the matching
  product) and it is now a column so the value is visible on the row you land on.
- **Messaging credentials.** Provider rows address an OpenConnector source by
  `sourceId` today. Moving that to the OpenRegister credential-broker
  (`CnCredentials`) is a follow-up: it changes where secrets live, which is a
  data-migration question, not a navigation one.

## Risks

- **The admin page has no vue-router.** It is its own webpack entry, so any moved
  view that navigated (`$router.push`) breaks there. `PosStaffList` / `PosRoleList`
  routed to a detail page and their forms routed back — they now emit, and a
  manager component opens the form in a dialog. Everything else moved was already
  router-free.
- **Deleting a page deletes its route.** Anything that deep-linked to
  `/pos/staff/:id`, `/settings/messaging`, `/settings/cti`, `/settings/payment`,
  `/pos/tender-types`, `/products-barcode` or `/billing-categories` will 404.
  These were configuration surfaces reached from the nav, not shared links.
