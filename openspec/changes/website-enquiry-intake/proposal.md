## Why

Nothing a visitor types on conduction.nl reaches the CRM, and it never has.

Three independent faults, each sufficient on its own:

1. The production bundle carries `pipelinqLeadEndpoint:"http://localhost:8080/..."`, because `PIPELINQ_LEAD_ENDPOINT` is not set in the deploy workflow. `LeadForm` detects the mixed-content failure at runtime and degrades to an email fallback, so every visitor is shown a `mailto:` link instead of a form that works.
2. Commit `eb60d5061` (shipped in stable 0.5.1) deleted `contactName`, `contactEmail`, `contactPhone` and `organisation` from `lead`. Those are the exact four fields the website posts. It left `message` behind, still described as "anonymous website intake", so the schema reads as though intake works.
3. The same commit made `client` and `pipeline` required on `lead`. The website posts neither, and `hardValidation` defaults to true, so the create returns HTTP 400.

Fault 2 and 3 are not regressions to revert. They are correct: a lead is a deal, with a value and a pipeline stage (`#1753`). An anonymous website enquiry is not a deal, and counting one as a deal makes every pipeline report wrong.

What is missing is the thing that comes before a lead.

## What Changes

- A new `enquiry` schema holds what a visitor typed, before anyone knows who they are: their name, email, phone and organisation as entered, their message verbatim, which form it came from, and which page they were on. No client, no pipeline, because neither is knowable at intake.
- A public intake endpoint `POST /api/enquiry` accepts it. The endpoint, not the submitter, decides `status`, `source` and `receivedAt`.
- The website posts to that endpoint instead of to OpenRegister's generic object API.

### Why an endpoint and not a direct object create

OpenRegister has no per-property scoping on create, and `readOnly` is explicitly a no-op on the create path (`ObjectService::enforceReadOnly`, "No-op on CREATE (uuid === null)"). So every property a schema declares is settable by whoever can create the object.

With `authorization.create: ["public"]` and a direct POST, an anonymous visitor could set `status: "converted"` and drop their own enquiry out of the sales inbox, or set `handledBy` to a real user's id. A whitelisting endpoint is the only place that can refuse that, because the schema cannot.

## Capabilities

### New Capabilities
- `website-enquiry-intake`: the `enquiry` schema, the public intake endpoint, its field whitelist, its source allowlist and its rate limit.
