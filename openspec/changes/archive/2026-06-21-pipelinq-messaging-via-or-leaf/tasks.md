# Tasks — messaging transport via the OpenRegister dispatch leaf

## 1. Re-point the SMS clients (MVP)

- [x] 1.1 `TwilioSmsClient::dispatchViaOpenConnector()` — resolve `MessageDispatchProvider` via the container (`class_exists` + container-miss guard → same `PermanentSmsProviderException` as today), call `dispatch(source: sourceId, body: payload, path: 'Messages.json', headers: [])`, read `['response']`, map a degraded `cause` to Transient/Permanent.
- [x] 1.2 `MessageBirdSmsClient::dispatchViaOpenConnector()` — same, `path: 'messages'`.
- [x] 1.3 `CmComSmsClient::dispatchViaOpenConnector()` — same, `path: 'messages'`.

## 2. Re-point the WhatsApp client (MVP)

- [x] 2.1 `WhatsAppProviderClient::dispatch()` — resolve `MessageDispatchProvider`, map the action to a vendor path (`send-template`/`send-text` → `messages`, `download-media`/`upload-media` → `media`, `list-templates` → `message_templates`), call `dispatch(source: sourceSlug, body: payload, path: ..., headers: [])`, read `['response']`, map degraded `cause`.

## 3. Cause mapping helper (MVP)

- [x] 3.1 Map leaf `cause` → exception identically across all four clients: `openconnector-source-missing` / `provider-auth` → Permanent; `openconnector-down` / `upstream-service-down` / unknown → Transient.

## 4. Tests (MVP)

- [x] 4.1 A send routes through `MessageDispatchProvider::dispatch()` with the right source slug + vendor-shaped body + path, and the provider message id is read from `response` (Twilio sid, MessageBird id, CM.com messageId, Meta wamid).
- [x] 4.2 A degraded `{ unavailable, cause }` leaf result yields the same failover behaviour as before: source-missing/provider-auth → Permanent (no failover); openconnector-down/upstream-service-down/unknown → Transient (failover).
- [x] 4.3 Leaf class absent (container miss / `class_exists` false) → `PermanentSmsProviderException` exactly as today.
- [x] 4.4 Suite ≥ baseline (1561 passing) in both default mode AND OR-leaf-loaded mode; `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`.

## 5. Verify

- [x] 5.1 Live on :8080 — an SMS/WhatsApp send routes to `MessageDispatchProvider` and degrades non-fatally (no fatal, no thrown `PermanentSmsProviderException('... lacks executeAction')`).
