- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Per-object authorization (IDOR prevention): every mutation endpoint that operates on a specific
  object MUST check that the authenticated user owns, is in the group of, or is admin for THAT
  object — not just that they are logged in. `#[NoAdminRequired]` opens the endpoint to all users;
  without a per-object check, any user can modify any object by guessing its ID.
  Pattern: fetch object → extract `assigneeUserId`/`assigneeGroupId`/`createdBy` → check
  (owner OR in group OR admin) → throw `OCSForbiddenException` if none apply. Extract into a
  reusable `authorizeXxx(object, user)` service method, called from every PUT/POST/DELETE.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- Nextcloud endpoint defaults: NO annotation = admin-only. Non-admin endpoints (agent/staff actions)
  MUST have `#[NoAdminRequired]` attribute. Pair every `#[NoAdminRequired]` with a per-object auth
  check — never trust the session alone for mutation.
- Input validation: all user-supplied strings that flow into URLs (query params, path segments)
  MUST be URL-encoded (`encodeURIComponent` in Vue/JS, `rawurlencode` in PHP). Email Message-IDs,
  file names, and free-text fields commonly contain `<`, `>`, `/`, `@`, `&` which break unencoded.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Error messages: use static, generic messages (`'Operation failed'`, `'Not authorized'`) — NEVER
  return `$e->getMessage()` to clients. Log the real error server-side with `$this->logger->error()`.
- Test collections: NEVER commit default credentials — use env variable placeholders.
