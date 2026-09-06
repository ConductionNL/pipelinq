# admin-settings

**Status:** Implemented

## Overview

The admin settings page provides a Nextcloud admin panel for configuring Pipelinq. Administrators can manage pipelines and their stages, set a default pipeline, configure lead source and request channel values, manage product categories, and configure prospect discovery (ICP) settings. Only Nextcloud admins can reach this page.

The Mail transports panel (marketing-mail-transports) lists every sending route, the instance mail server, a sender's Mail account, or a bulk provider through OpenConnector, with a toggle for active and default. Each row shows a cached SPF, DKIM and DMARC verdict for its sender domain, refreshed on request. A `mailAccount` row needs the Nextcloud Mail app installed; a `provider` row never stores a credential itself, only a reference to an OpenConnector source. The `getSymfonyEmail()` header path the instance transport uses for extra headers is a private Nextcloud API, not part of `OCP\Mail\IMessage`; it degrades soft (and logs) if a future core release removes it.

## Screenshot

![admin-settings](/screenshots/admin-settings.png)

## Specification

Full specification: `openspec/specs/admin-settings/spec.md`

## Related Files

- Spec: `openspec/changes/admin-settings/specs/admin-settings/spec.md`
- Design: `openspec/changes/admin-settings/design.md`
- Tasks: `openspec/changes/admin-settings/tasks.md`
