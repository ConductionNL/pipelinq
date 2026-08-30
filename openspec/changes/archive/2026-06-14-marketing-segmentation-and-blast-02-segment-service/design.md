# Design: 02 Segment Service

## Scope

`lib/Service/SegmentService.php` only. Reads the Segment/Contact/Customer
schemas declared in member 01 via `ObjectService` (ADR-001, ADR-022). No
declarative JSON edits, no Vue.

## Methods

- `validateRules(array $rules, string $entityType): ?string` — recursive
  traversal; leaf predicates verify field exists in entity schema (via
  `SchemaMapService`), operator valid for type (equals/gt/gte/lt/lte/
  contains/in/between), value coercible. Returns null if valid.
- `evaluateRules(array $rules, array $entity): bool` — AND = all children
  true, OR = any child true; leaf compares entity field against rule value
  with type coercion (e.g. "90 days" → integer day offset).
- `estimateSize(string $segmentId): int` — load Segment, query all objects
  of matching entityType, count matches, cache with TTL (app config, default
  3600s) via `ICacheFactory`.
- `getMembersForBlast(string $segmentId): array` — query matching objects,
  return minimal `[contactId, email, firstName, lastName]` projection.

## Security / patterns

ADR-003: service holds logic, controllers stay thin (controllers added in
member 06). ADR-001/022: all CRUD via `ObjectService`; no raw SQL.
Inject `ObjectService`, `SchemaMapService`, `IAppConfig`, `LoggerInterface`,
`ICacheFactory`. `@spec` PHPDoc on public methods.
