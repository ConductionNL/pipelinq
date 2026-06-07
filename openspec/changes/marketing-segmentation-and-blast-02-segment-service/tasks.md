# Tasks: 02 Segment Service

## SegmentService (Task 2.1 of giant)

- [x] Create `lib/Service/SegmentService.php`
- [x] Implement `validateRules(array $rules, string $entityType): ?string` — recursive traversal; verify field exists, operator valid for type, value coercible; return null if valid
- [x] Field resolution via `SchemaMapService` to get Contact/Customer field definitions
- [x] Support operators: equals, gt, gte, lt, lte, contains, in, between per field type
- [x] Implement `evaluateRules(array $rules, array $entity): bool` — AND (all children), OR (any child), leaf compare with type coercion
- [x] Implement `estimateSize(string $segmentId): int` — load Segment, query matching entityType objects, count matches, cache with TTL (default 3600s)
- [x] Implement `getMembersForBlast(string $segmentId): array` — return `[contactId, email, firstName, lastName]` projection
- [x] Inject `ObjectService`, `SchemaMapService`, `IAppConfig`, `LoggerInterface`, `ICacheFactory`
- [x] Add `@spec` PHPDoc with task reference
