# Pipelinq gets the store the fleet already built

## Why

OpenRegister owns store discovery. ADR-080 decided that, the AppHost engine
shipped it as `GenericStoreService` + `StoreDescriptor`, openbuild proved the
contract by deleting its own 331-line proxy and injecting the engine's, and
dossiq adopted it for case configuration.

Pipelinq never adopted it. An administrator who wants a sales pipeline, a
routing set-up for the service desk or a POS configuration has one way to get
one: build it by hand. Every organisation running pipelinq builds the same
handful of pipelines and queues independently, and none of them can publish
one.

## What changes

A `Store` surface backed by the engine's discovery client:

- `StoreDescriptor` naming `commercial-template` objects in the configured
  remote register, with a `kind` discriminator per ADR-080 Decision 5. The
  kinds name commercial configuration (`pipeline`, `queue-routing`,
  `catalogue`, `pos-setup`, `loyalty-programme`), so one registry can serve
  pipelinq and dossiq from one schema without either app filtering by app id.
- `StoreController::search()`, a thin action over `GenericStoreService`, by
  composition, per Decision 3.
- `StoreController::install()`, pipelinq's own, because install does not
  generalise. It resolves the register through `RegisterResolverService` and
  the schema through the `<slug>_schema` app-config key that OpenRegister's
  import already writes, then writes through `ObjectServiceInterface`.
- Registry connection settings, with the token write-only.
- The `Store` page and a footer menu entry at order 92, matching dossiq.

## The refusal that matters

**Install accepts configuration schemas and refuses record schemas.** A store
item may carry a `pipeline`, a `queue`, a `skill`, a catalogue or a POS set-up.
It may not carry a `client`, a `contact`, a `lead`, a `ticket`, a `task`, a
`contract` or a `posTransaction`.

Without that line the install path is a remote write primitive: a registry, or
anyone who can answer as one, could push objects into an organisation's live
commercial records through a button labelled "Install". The allowlist is not a
convenience, it is the boundary.

A second boundary sits behind it and is easy to miss: the allowlist governs
WHICH schema a component may write, never whether the write creates or
replaces. `saveObject()` resolves its target from the payload, so a component
naming a perfectly legitimate `pipeline` and carrying the uuid of a live one
would replace it, PUT-semantically, gutting every key the payload omitted.
`asNewObject()` strips `id`, `uuid` and `@self` for that reason.

## What this does not do

Publishing. Pipelinq consumes a registry; it does not answer as one.

## Next

Connect a registry under Administration settings, then open Store from the
footer. With none connected the page renders the three built-in templates and
makes no outbound request, which is the ADR-080 Decision 4 fallback.
