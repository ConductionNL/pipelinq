---
sidebar_position: 2
title: MijnOverheid Berichtenbox
description: Two-way bridge between Pipelinq zaken/contactmomenten and the Dutch government MijnOverheid Berichtenbox via the Logius BBK 1.7 koppelvlak.
draft: true
---

# MijnOverheid Berichtenbox bridge

The Berichtenbox bridge delivers zaak-status updates to citizens in their
MijnOverheid Berichtenbox — the government-mandated digital mailbox — and routes
their replies back into Pipelinq as new contactmomenten on the same zaak. It
implements the Logius Berichtenbox-koppelvlak (BBK) 1.7 binding and the
compliance obligations of the WMEBV 2023, the Wet Digitale Overheid, the AVG and
the Archiefwet.

## What it does

- **Outbound status push** — when a zaak transitions to a terminal status, a
  message is rendered from a per-zaaktype-per-status template and delivered to
  the citizen's Berichtenbox.
- **Inbound reply ingestion** — a reply in MijnOverheid arrives via a
  signature-verified Logius webhook and becomes a new contactmoment on the same
  zaak, routed to the original handling ambtenaar.
- **BSN → mailbox resolution** — a 24-hour TTL cache (keyed by an HMAC hash of
  the BSN, never the plaintext) avoids a Logius round-trip on every dispatch.
- **5-working-day fallback** — a message left unread for five Dutch working days
  (weekends and official holidays excluded) is mirrored as email (BBK 1.7
  Art. 3.5).
- **Append-only audit log** — every delivery event is written immutably with a
  SHA-256 payload hash and an Archiefwet retention date.

## Prerequisites

1. A Logius client registration (OAuth 2.0 client-credentials) for the
   Berichtenbox-koppelvlak.
2. A PKIoverheid certificate + private key for outbound request signing.
3. A configured SMTP transport in Nextcloud (for the email fallback).

## Configuration

All secrets are stored in the Nextcloud app-config vault (`sensitive` values,
excluded from `occ config:list` and support archives) — never in code. Set them
via `occ config:app:set pipelinq <key> --value <secret> --sensitive`:

| Key | Purpose |
| --- | --- |
| `berichtenbox_logius_token_url` | Logius OAuth token endpoint |
| `berichtenbox_logius_api_base` | Logius Berichtenbox API base URL |
| `berichtenbox_logius_client_id` | OAuth client id |
| `berichtenbox_logius_client_secret` | OAuth client secret |
| `berichtenbox_logius_webhook_secret` | HMAC secret for inbound webhook verification |
| `berichtenbox_pki_key` | PKIoverheid private key (PEM) for request signing |
| `berichtenbox_fallback_sender` | From-address for fallback emails |

The BSN encryption key and HMAC pepper are provisioned automatically on first
use and stored as sensitive app-config values. Provision them out-of-band in
production for controlled key management and rotation.

## Templates

Templates live as `berichtenboxTemplate` objects in the Pipelinq OpenRegister
register, keyed by `zaaktype`, `status` and `language`. The subject and body use
`{{variable}}` placeholders (`zaakId`, `status`, `gemeente`, `deadline`,
`deepLink`); values are HTML-escaped and the body is validated as well-formed
XHTML before dispatch. Three Dutch starter templates (paspoort, rijbewijs, AVG
inzage) ship as seed data.

## Webhooks

Configure these public, signature-verified callback URLs in your Logius
registration. Each request is rejected with `401` unless its body matches the
`X-Logius-Signature` HMAC.

- `POST /apps/pipelinq/api/webhook/berichtenbox/read` — read receipts
- `POST /apps/pipelinq/api/webhook/berichtenbox/reply` — inbound replies

## Operations

- **Dispatch** runs every 5 minutes (`DispatchQueuedMessagesJob`); failed sends
  retry with exponential backoff (1m, 5m, 15m, 1h, 4h) before requiring manual
  intervention.
- **Fallback** runs daily (`FallbackEmailJob`).
- **Stats:** `GET /apps/pipelinq/api/admin/berichtenbox/stats` (admin only).
- **Manual retry:** `POST /apps/pipelinq/api/admin/berichtenbox/message/{id}/retry`
  (admin only) re-queues a `failed` message.

## Security & privacy

- BSNs are encrypted at rest with AES-256-GCM and indexed only by an HMAC hash;
  they are masked (`1*******9`) in logs and never appear in plaintext.
- Inbound webhooks are HMAC-verified; an unsigned or mismatched request is
  rejected.
- Replies are scoped to their parent message, so a forged `parentMessageId`
  cannot attach a reply to an unrelated citizen's zaak.
- AVG Art. 17 erasure is supported via crypto-shredding, which overwrites the
  encrypted BSN with an undecryptable value while leaving the audit trail
  intact.
