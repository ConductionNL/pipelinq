<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Customer portal — administrator guide

The customer portal is a separate-auth-domain self-service site for your B2B and
B2C customers. Portal accounts are **not** Nextcloud users: a customer signing in
to the portal never gains any access to your Nextcloud (files, admin, etc.).

## Enabling the portal for a tenant

1. The portal register (`pipelinq-portal`) is created automatically on app
   upgrade. Confirm `occ config:app:get pipelinq portal_register` is non-empty.
2. Open **Portal → Admin** (`/apps/pipelinq/portal#/admin`, Nextcloud admins
   only) and save a tenant configuration. Until you do, the portal serves
   defaults (cobalt branding, all read features enabled).

## Branding (≈15 minutes)

In the admin panel set:

- **Display name**, **logo**, **favicon**
- **Brand colours** — the primary colour is contrast-validated against the
  background. A combination below WCAG 2.2 AA (4.5:1) is rejected with a clear
  message, so you cannot ship an inaccessible portal by mistake.
- **Custom domain** (`klant.example.nl`) or a subdomain.
- **Support email / phone**, shown on every page.

## Features and modes

Toggle which read features (invoices, contracts, orders, requests, documents,
profile) are exposed, and whether **B2B** and/or **B2C** modes are enabled.
Disabled features return 404 and are hidden from the navigation.

## Widget embedding

Enable **widget mode** and list the **allowed origins**. The portal can then be
embedded as an `<iframe src=".../portal/widget?tenant=...">` on those origins
only; requests from other origins are refused (403). The widget auto-resizes its
host via `postMessage`.

## Managing portal accounts

The admin panel lists a tenant's portal accounts (email, type, status, last
login). Accounts are created against existing client-management contacts; a
portal account carries only credential state (no business data).

## DPO / audit workflow

Every sensitive action (login, MFA, profile change, document download, request,
export, account closure) is recorded in an **append-only** audit trail. As an
admin/DPO you can read a tenant's full trail at **Admin → Audit events** (or
`GET /portal/api/admin/audit-events`).

## AVG (GDPR) requests

- **Data export (Art. 15)** — the customer self-serves from **Privacy → Request
  my data**; a 30-day signed download link is emailed.
- **Account closure (Art. 17)** — the customer self-serves from **Privacy →
  Close my account** (email-confirmed). The linked contact is retained for legal
  (7-year) retention and pseudonymised later by the nightly cleanup once no
  retention obligation remains. Run it on demand with
  `occ pipelinq:portal:cleanup`.
