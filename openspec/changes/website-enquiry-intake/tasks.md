# Tasks

## 1. The schema

- [x] 1.1 Add the `enquiry` schema as a `register.d` fragment, so the monolith is not touched and a concurrent feature build cannot conflict on it.
- [x] 1.2 Declare `authorization.create: ["public", "authenticated"]`, with read, update and delete authenticated only.
- [x] 1.3 Require `title` and nothing else. `client` and `pipeline` are not knowable at intake, which is the whole reason this is not a `lead`.
- [x] 1.4 Give it a lifecycle: `new` to `converted`, `spam` or `closed`, each transition authorised to the sales group.
- [x] 1.5 Add `enquiry` to `SettingsLoadService::SCHEMA_SLUGS`, or the `enquiry_schema` app-config key is never written and the intake service cannot resolve the schema.
- [x] 1.6 Test: the fragment declares the four fields the website posts, and `required` contains neither `client` nor `pipeline`.
- [x] 1.7 Test: `SCHEMA_SLUGS` contains `enquiry`. A missing slug fails at import with no error anyone sees.

## 2. The intake endpoint

- [x] 2.1 `POST /api/enquiry`, public, no CSRF, anon rate-limited, accepting form-urlencoded so the cross-origin POST stays a CORS simple request.
- [x] 2.2 Reflect the request Origin on the response so the submitting page can read the result.
- [x] 2.3 Whitelist the nine fields a submitter may set. Set `status`, `receivedAt` and the resolved `source` server-side.
- [x] 2.4 Refuse a `source` that is not on the allowlist, with nothing written.
- [x] 2.5 Refuse a submission with neither a message nor an email address.
- [x] 2.6 Refuse a filled honeypot.
- [x] 2.7 Catch `Throwable` and answer opaquely. An uncaught throw on a public endpoint returns a stack trace to an anonymous caller.
- [x] 2.8 Test: a payload carrying `status: "converted"`, `lead` and `handledBy` stores none of the three. This is the reason the endpoint exists rather than a direct object create, so it is the test that must fail if the whitelist is removed.
- [x] 2.9 Test: a real anonymous submission succeeds AND the contact details read back unchanged. Asserting the 201 alone would pass against a write that validated its payload away.

## 3. Wiring and follow-on

- [x] 3.1 Route in `appinfo/routes.php`, declared before the SPA catch-all.
- [ ] 3.2 Run the diff check and open the PR against `development`.

## Out of scope, tracked separately

- The convert action that turns an enquiry into a client, a contact and a lead. The schema carries the three link fields and the lifecycle transition so the data model is complete, but a transition with no call site is a guard nobody has watched refuse. It ships with its caller or not at all.
- The conduction-website change that posts here. It lives in another repo and is the next PR.
