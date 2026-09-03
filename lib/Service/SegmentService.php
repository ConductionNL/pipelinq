<?php

/**
 * Pipelinq SegmentService.
 *
 * Rule-tree validator and evaluator that makes a Segment a live query, not
 * a frozen list. Validates and evaluates AND/OR rule trees against the
 * configured entity schema (contact or customer), estimates membership
 * size with TTL caching, and projects the per-recipient send list used by
 * the blast engine (member 04).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * SegmentService — validates and evaluates Segment rule trees.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Aggregates the whole
 *  rule-tree lifecycle (evaluate, validate, estimate, project) as many
 *  small, single-purpose methods; the 2026-07 phpmd cleanup deliberately
 *  extracted per-operator/per-node-type helpers to bring every method's own
 *  complexity under threshold, which grows line count without adding real
 *  tangled logic. Splitting the class would scatter one cohesive
 *  rule-engine concern across several classes.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Same rationale: the
 *  operator-dispatch table, node-type dispatch, and value-coercion helpers
 *  are each one-operator/one-type single-purpose methods, intentionally
 *  kept small and numerous instead of a few large branchy ones.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class sums many
 *  independently-simple methods (each verified under phpmd's per-method
 *  thresholds); the total reflects breadth of the rule-tree surface
 *  (evaluate/validate/estimate/project), not tangled logic.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */
class SegmentService {
	/**
	 * Default estimateSize cache TTL in seconds when the
	 * `segment.estimate_ttl_seconds` app config key is unset.
	 */
	private const DEFAULT_ESTIMATE_TTL_SECONDS = 3600;

	/**
	 * Default contact schema slug used when no `contact_schema` app
	 * config value is set. Matches the seed register slug.
	 */
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * Default Segment schema slug used when no `segment_schema` app
	 * config value is set.
	 */
	private const DEFAULT_SEGMENT_SCHEMA_SLUG = 'segment';

	/**
	 * Default register slug used when no `register` app config value is
	 * set. Pipelinq's canonical register is also called `pipelinq`.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default customer schema slug used when no `customer_schema` /
	 * `client_schema` app config value is set. Pipelinq historically
	 * uses `client` as the customer slug.
	 */
	private const DEFAULT_CUSTOMER_SCHEMA_SLUG = 'client';

	/**
	 * Operator → field-type compatibility matrix. Each key is the leaf
	 * predicate's operator; the value is the set of JSON-schema types
	 * the operator may legally be applied to.
	 *
	 * The operator list is the union of the matrix documented in the
	 * member-02 design (equals/gt/gte/lt/lte/contains/in/between) PLUS
	 * the operators present in the seed Segment rule trees (notIn,
	 * before, after, containsAny, greaterThan, lessThan). Treating these
	 * as first-class avoids forcing slice 01's seed data through a
	 * rename pass.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const OPERATOR_TYPE_MATRIX = [
		'equals' => ['string', 'integer', 'number', 'boolean', 'array'],
		'notEquals' => ['string', 'integer', 'number', 'boolean', 'array'],
		'gt' => ['integer', 'number', 'string'],
		'gte' => ['integer', 'number', 'string'],
		'lt' => ['integer', 'number', 'string'],
		'lte' => ['integer', 'number', 'string'],
		'greaterThan' => ['integer', 'number', 'string'],
		'greaterThanOrEqual' => ['integer', 'number', 'string'],
		'lessThan' => ['integer', 'number', 'string'],
		'lessThanOrEqual' => ['integer', 'number', 'string'],
		'before' => ['string'],
		'after' => ['string'],
		'between' => ['integer', 'number', 'string'],
		'contains' => ['string', 'array'],
		'containsAny' => ['string', 'array'],
		'in' => ['string', 'integer', 'number', 'boolean'],
		'notIn' => ['string', 'integer', 'number', 'boolean'],
		'isNull' => ['string', 'integer', 'number', 'boolean', 'array'],
		'isNotNull' => ['string', 'integer', 'number', 'boolean', 'array'],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param SchemaMapService $schemaMapService Schema-slug map.
	 * @param ICacheFactory $cacheFactory NC cache factory.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SchemaMapService $schemaMapService,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate a rule tree against the schema for the given entity type.
	 *
	 * Walks the tree recursively. Composite nodes (AND/OR/NOT) must
	 * declare a `children` array; each child is either another composite
	 * or a leaf {field, operator, value}. Each leaf is validated against
	 * the resolved schema's properties:
	 *
	 * - field must exist as a property on the schema
	 * - operator must be one of OPERATOR_TYPE_MATRIX's keys
	 * - operator must be compatible with the property's `type` (so
	 *   `industry > 50` on a string field is rejected)
	 * - value must be coercible to the field type (so
	 *   `employees = "not-a-number"` is rejected)
	 *
	 * Returns NULL when the tree is structurally and semantically valid.
	 * Returns a human-readable error string locating the first failure;
	 * the caller surfaces this as a field-level save error.
	 *
	 * @param array<string, mixed> $rules The rule tree (AND/OR node).
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return string|null NULL on success, otherwise the first error.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function validateRules(array $rules, string $entityType): ?string {
		$properties = $this->resolveSchemaProperties(entityType: $entityType);
		if ($properties === null) {
			return sprintf('Unknown entityType "%s" (no schema mapping configured).', $entityType);
		}

		return $this->validateNode(node: $rules, path: '$', properties: $properties);
	}//end validateRules()

	/**
	 * Evaluate a validated rule tree against one entity payload.
	 *
	 * AND nodes return true when ALL children return true.
	 * OR nodes return true when ANY child returns true.
	 * NOT nodes (single child) invert the child's truth value.
	 * Leaf predicates compare the entity's field against the rule value
	 * with type-aware coercion (date strings → unix-timestamp for
	 * before/after, scalars → float for numeric comparisons, scalars →
	 * lower-cased strings for equals/contains).
	 *
	 * Missing fields evaluate to false (predicate fails) rather than
	 * throwing — a Contact without a `lastContactMoment` is simply not
	 * a match for `lastContactMoment < 90 days`.
	 *
	 * @param array<string, mixed> $rules Rule tree node.
	 * @param array<string, mixed> $entity Entity payload (key-value).
	 *
	 * @return bool True when the entity matches the tree.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function evaluateRules(array $rules, array $entity): bool {
		return $this->evaluateNode(node: $rules, entity: $entity);
	}//end evaluateRules()

	/**
	 * Validate a rule tree and return its size estimate in one call.
	 *
	 * Convenience entry point for the segment-builder save path used by
	 * controllers (member 06) and BlastService (member 04) — it runs
	 * `validateRules()` on the supplied rule tree, returns a structured
	 * `{ valid, error, estimatedSize }` triple, and counts matching
	 * entities only when the tree is valid. Centralising the
	 * validate-then-estimate sequence here keeps every caller honest
	 * about the order: never persist a Segment whose tree did not pass
	 * `validateRules()` first.
	 *
	 * @param array<string, mixed> $rules Rule tree from the request.
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return array{valid: bool, error: ?string, estimatedSize: int}
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function previewRulePayload(array $rules, string $entityType): array {
		$error = $this->validateRules(rules: $rules, entityType: $entityType);
		if ($error !== null) {
			return [
				'valid' => false,
				'error' => $error,
				'estimatedSize' => 0,
			];
		}

		$count = 0;
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) === true) {
				$count++;
			}
		}

		return [
			'valid' => true,
			'error' => null,
			'estimatedSize' => $count,
		];
	}//end previewRulePayload()

	/**
	 * Return the minimal recipient projection used by the blast engine.
	 *
	 * Same query path as estimateSize, but returns one row per matching
	 * entity carrying just the fields a downstream Blast needs:
	 * `contactId`, `email`, `firstName`, `lastName`. No caching — a stale
	 * send list is worse than a slow one (compliance member 03 still
	 * filters this list against ConsentRecord before dispatch, but
	 * delivering to a deleted Contact would be a defect).
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return array<int, array<string, string>> Recipient rows.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segments-are-live-not-frozen-lists
	 */
	public function getMembersForBlast(string $segmentId): array {
		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return [];
		}

		$rules = $this->extractRules(segment: $segment);
		$entityType = $this->extractEntityType(segment: $segment);
		if ($rules === null || $entityType === null) {
			return [];
		}

		$members = [];
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) !== true) {
				continue;
			}

			$members[] = $this->projectMember(entity: $entity);
		}

		return $members;
	}//end getMembersForBlast()

	/**
	 * List Segments with pagination envelope.
	 *
	 * Used by the marketing Vue views (member 07) to browse the segment
	 * library. Filtered list is server-paged so the views never load the
	 * entire register; the envelope shape mirrors `BlastService::listBlasts()`.
	 *
	 * @param int $page 1-based page number.
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function listSegments(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadEntities(schemaSlug: $this->getSegmentSchemaSlug(), filters: []);
		$total = count($all);
		$slice = array_slice($all, (($page - 1) * $limit), $limit);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}//end listSegments()

	/**
	 * Compute the page-count from a total + page-size pair.
	 *
	 * Centralised so the inline ternary stays out of the envelope
	 * builder (matches the team's "no inline IF" coding style).
	 *
	 * @param int $total Total row count.
	 * @param int $limit Page size.
	 *
	 * @return int Page count (0 when total is 0).
	 */
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}//end computePages()

	/**
	 * Public accessor returning one Segment payload (or null).
	 *
	 * Returns the raw Segment with the rules tree as stored, enabling the
	 * controller to layer an `estimatedSize` field on top via
	 * `estimateSize()` — kept as two separate methods so the rule-tree
	 * fetch path is reusable without paying for the count when the
	 * caller does not need it.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return array<string, mixed>|null The Segment payload or null.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function getSegmentById(string $segmentId): ?array {
		if ($segmentId === '') {
			return null;
		}

		return $this->loadSegment(segmentId: $segmentId);
	}//end getSegmentById()

	/**
	 * Create a Segment after validating the rule tree.
	 *
	 * Runs `validateRules()` against the resolved entity-schema before
	 * touching ObjectService — an invalid tree is rejected with a
	 * generic-but-actionable error and no row is persisted. `createdBy`
	 * is set from the authenticated user id (ADR-005); the request body
	 * is never trusted for this field.
	 *
	 * @param array<string, mixed> $payload Inbound Segment payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{segment?: array<string, mixed>, error?: string, estimatedSize?: int}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function createSegment(array $payload, string $createdByUid): array {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return ['error' => 'Invalid name'];
		}

		$entityType = strtolower((string)($payload['entityType'] ?? ''));
		if (in_array($entityType, ['contact', 'customer'], true) === false) {
			return ['error' => 'Invalid entityType'];
		}

		$rules = ($payload['rules'] ?? null);
		if (is_array($rules) === false) {
			return ['error' => 'Invalid rules'];
		}

		$error = $this->validateRules(rules: $rules, entityType: $entityType);
		if ($error !== null) {
			return ['error' => 'Invalid rule tree: ' . $error];
		}

		return $this->persistSegment(payload: $payload, rules: $rules, entityType: $entityType, createdByUid: $createdByUid);
	}//end createSegment()

	/**
	 * Persist a validated Segment payload and return the saved row.
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 * @param array<string, mixed> $rules Validated rule tree.
	 * @param string $entityType "contact" or "customer".
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{segment?: array<string, mixed>, error?: string, estimatedSize?: int}
	 */
	private function persistSegment(array $payload, array $rules, string $entityType, string $createdByUid): array {
		$now = gmdate('Y-m-d\TH:i:s\Z');
		$object = [
			'name' => (string)$payload['name'],
			'description' => (string)($payload['description'] ?? ''),
			'rules' => $rules,
			'entityType' => $entityType,
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->saveSegmentObject(payload: $object);
		if ($saved === null) {
			return ['error' => 'Could not create segment'];
		}

		$estimated = 0;
		$idForSize = $this->extractSegmentId(payload: $saved);
		if ($idForSize !== '') {
			$estimated = $this->estimateSize(segmentId: $idForSize);
			$saved['estimatedSize'] = $estimated;
		}

		return ['segment' => $saved, 'estimatedSize' => $estimated];
	}//end persistSegment()

	/**
	 * Project the per-recipient list used to preview a Segment's members.
	 *
	 * Reuses `getMembersForBlast()` so the preview shape matches what the
	 * blast engine will actually consume. Capped at `$limit` rows so the
	 * controller cannot accidentally exhaust memory on a million-row
	 * segment.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 * @param int $limit Max rows returned (clamped 1..500).
	 *
	 * @return array<int, array<string, string>> Recipient rows.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function previewSegmentMembers(string $segmentId, int $limit = 50): array {
		$limit = min(500, max(1, $limit));
		if ($segmentId === '') {
			return [];
		}

		$members = $this->getMembersForBlast(segmentId: $segmentId);
		return array_slice($members, 0, $limit);
	}//end previewSegmentMembers()

	/**
	 * Recompute the Segment's `estimatedSize` and persist it.
	 *
	 * Backs the `POST /api/segments/:id/size` endpoint — used by the
	 * segment-builder to refresh the cached count after rule edits or
	 * after the underlying contact pool has changed. Returns the freshly
	 * computed value so the caller does not have to re-read.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return int The refreshed estimated size; 0 when the Segment is
	 *             missing or unreachable.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
	 */
	public function refreshSegmentSize(string $segmentId): int {
		if ($segmentId === '') {
			return 0;
		}

		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return 0;
		}

		// 🔴 A REFRESH THAT READS THE CACHE IS NOT A REFRESH.
		//
		// This called estimateSize(), which returns the cached count when one
		// is present -- so POST /api/segments/{id}/size answered, and then
		// PERSISTED, the very stale estimate the caller asked it to replace.
		// Editing a segment's rules and refreshing left the old number on the
		// record, looking freshly computed.
		$size = $this->recomputeSize(segmentId: $segmentId);
		$payload = $segment;
		$payload['estimatedSize'] = $size;
		$payload['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

		// 🔴 AND A FAILED WRITE IS NOT A REFRESH EITHER.
		//
		// saveSegmentObject() answers null when the write failed, and that
		// return was discarded, so the endpoint reported 200 with the new count
		// over a record that still holds the old one. Throwing lets the
		// controller answer honestly; the caller can retry.
		$saved = $this->saveSegmentObject(
			payload: $payload,
			id: $this->extractSegmentId(payload: $segment)
		);
		if ($saved === null) {
			throw new RuntimeException('Pipelinq: the refreshed segment size could not be persisted.');
		}

		return $size;
	}//end refreshSegmentSize()

	/**
	 * Persist a Segment payload via OpenRegister ObjectService.
	 *
	 * @param array<string, mixed> $payload Segment payload.
	 * @param string|null $id Existing id when patching.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 */
	private function saveSegmentObject(array $payload, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getSegmentSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.saveSegmentObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveSegmentObject()

	/**
	 * Load every object of the supplied schema (Segment list helper).
	 *
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Filter map.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 */
	private function loadEntities(string $schemaSlug, array $filters): array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $register,
							'schema' => $schemaSlug,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.loadEntities: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadEntities()

	/**
	 * Extract the id from a Segment payload (uuid > id > slug).
	 *
	 * @param array<string, mixed> $payload Segment payload.
	 *
	 * @return string Id or empty string.
	 */
	private function extractSegmentId(array $payload): string {
		$id = $this->firstScalarValue(source: $payload, keys: ['uuid', 'id', 'slug']);
		if ($id !== '') {
			return $id;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalarValue(source: $payload['@self'], keys: ['uuid', 'id', 'slug']);
		}

		return '';
	}//end extractSegmentId()

	/**
	 * First non-empty scalar value among the given keys.
	 *
	 * Extracted so {@see extractSegmentId()} (and other id-resolution
	 * helpers) share one implementation; behaviour is unchanged: a
	 * missing key or a non-scalar / empty-string value is skipped.
	 *
	 * @param array<string, mixed> $source Source array to probe.
	 * @param array<int, string> $keys Keys to check, in priority order.
	 *
	 * @return string The first matching value, or empty when none match.
	 */
	private function firstScalarValue(array $source, array $keys): string {
		foreach ($keys as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end firstScalarValue()

	/**
	 * Build the minimal member-projection row used by the blast engine.
	 *
	 * Supports the common shapes seen across the Conduction fleet:
	 * - flat key names (`email`, `firstName`)
	 * - snake_case fallbacks (`first_name`, `last_name`)
	 * - vCard-style `name` (split on whitespace) when no first/last
	 *
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return array<string, string> Recipient row.
	 */
	private function projectMember(array $entity): array {
		$id = $this->resolveMemberId(entity: $entity);
		$name = $this->resolveMemberName(entity: $entity);

		return [
			'contactId' => $id,
			'email' => (string)($entity['email'] ?? ''),
			'firstName' => $name['firstName'],
			'lastName' => $name['lastName'],
		];
	}//end projectMember()

	/**
	 * Resolve a member's id (id > uuid > slug, then the same in `@self`).
	 *
	 * Extracted from {@see projectMember()} so it stays within the
	 * complexity budget; behaviour is unchanged, including the `@self`
	 * fallback loop NOT requiring a non-empty value (matching the
	 * pre-refactor `isset() && is_scalar()` check exactly).
	 *
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return string Id or empty string.
	 */
	private function resolveMemberId(array $entity): string {
		$id = $this->firstScalarValue(source: $entity, keys: ['id', 'uuid', 'slug']);
		if ($id !== '') {
			return $id;
		}

		if (isset($entity['@self']) === true && is_array($entity['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				if (isset($entity['@self'][$key]) === true && is_scalar($entity['@self'][$key]) === true) {
					return (string)$entity['@self'][$key];
				}
			}
		}

		return '';
	}//end resolveMemberId()

	/**
	 * Resolve a member's first/last name, falling back to splitting a
	 * vCard-style `name` on whitespace when neither is present.
	 *
	 * Extracted from {@see projectMember()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return array{firstName: string, lastName: string}
	 */
	private function resolveMemberName(array $entity): array {
		$firstName = (string)($entity['firstName'] ?? $entity['first_name'] ?? '');
		$lastName = (string)($entity['lastName'] ?? $entity['last_name'] ?? '');
		if ($firstName === '' && $lastName === '' && isset($entity['name']) === true && is_string($entity['name']) === true) {
			$parts = preg_split('/\s+/', trim($entity['name']));
			if (is_array($parts) === true && $parts !== []) {
				$firstName = (string)array_shift($parts);
				$lastName = trim(implode(' ', $parts));
			}
		}

		return ['firstName' => $firstName, 'lastName' => $lastName];
	}//end resolveMemberName()

	/**
	 * Estimate the size of a Segment by counting matching entities.
	 *
	 * Loads the Segment, resolves its entityType schema, queries every
	 * object of that type via OpenRegister's ObjectService, evaluates
	 * the rule tree against each, and returns the match count. The
	 * result is cached via the NC distributed cache with the TTL given
	 * by the `segment.estimate_ttl_seconds` app config key (default
	 * 3600s) — repeated previews on the same Segment within the TTL
	 * window hit cache instead of re-scanning.
	 *
	 * Returns 0 when the Segment is not found, its rules are invalid,
	 * or the OpenRegister query fails — the count is a preview, not an
	 * authoritative billing input, so failing to 0 is preferable to
	 * raising on the segment-detail view.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return int Count of matching entities; 0 on failure.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segments-are-live-not-frozen-lists
	 */
	public function estimateSize(string $segmentId): int {
		$cached = $this->readEstimateCache(
			cache: $this->getEstimateCache(),
			cacheKey: ('estimate:' . $segmentId)
		);
		if ($cached !== null) {
			return $cached;
		}

		return $this->recomputeSize(segmentId: $segmentId);
	}//end estimateSize()

	/**
	 * Count a segment's members, ignoring any cached estimate, and re-cache.
	 *
	 * Split from {@see estimateSize()} rather than gated by a boolean argument
	 * on it: the two answer different questions — "what is this segment's size"
	 * versus "count it again now" — and a flag that switches a method between
	 * two behaviours is the shape phpmd's BooleanArgumentFlag rule names.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 *
	 * @return int The freshly counted size.
	 */
	public function recomputeSize(string $segmentId): int {
		$cache = $this->getEstimateCache();
		$cacheKey = 'estimate:' . $segmentId;

		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return 0;
		}

		$rules = $this->extractRules(segment: $segment);
		$entityType = $this->extractEntityType(segment: $segment);
		if ($rules === null || $entityType === null) {
			return 0;
		}

		$count = $this->countMatchingEntities(rules: $rules, entityType: $entityType);

		if ($cache !== null) {
			$ttl = $this->getEstimateTtl();
			$cache->set($cacheKey, $count, $ttl);
		}

		return $count;
	}//end recomputeSize()

	/**
	 * Read a cached estimate count, if present and int-like.
	 *
	 * Extracted from {@see estimateSize()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param ?ICache $cache The estimate cache, or null when unavailable.
	 * @param string $cacheKey The cache key.
	 *
	 * @return int|null The cached count, or null on a cache miss / unavailable cache.
	 */
	private function readEstimateCache(?ICache $cache, string $cacheKey): ?int {
		if ($cache === null) {
			return null;
		}

		$cached = $cache->get($cacheKey);
		if (is_int($cached) === true) {
			return $cached;
		}

		if (is_numeric($cached) === true) {
			return (int)$cached;
		}

		return null;
	}//end readEstimateCache()

	/**
	 * Count the entities of a type that match a rule tree.
	 *
	 * Extracted from {@see estimateSize()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $rules The rule tree.
	 * @param string $entityType The entity schema slug.
	 *
	 * @return int Count of matching entities.
	 */
	private function countMatchingEntities(array $rules, string $entityType): int {
		$count = 0;
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) === true) {
				$count++;
			}
		}

		return $count;
	}//end countMatchingEntities()

	/**
	 * Recursively evaluate a node against the entity.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return bool Truth value of this node.
	 */
	private function evaluateNode(array $node, array $entity): bool {
		$type = $this->nodeType(node: $node);
		if ($type === 'AND') {
			return $this->evaluateAndChildren(children: ($node['children'] ?? []), entity: $entity);
		}

		if ($type === 'OR') {
			return $this->evaluateOrChildren(children: ($node['children'] ?? []), entity: $entity);
		}

		if ($type === 'NOT') {
			return $this->evaluateNotChild(node: $node, entity: $entity);
		}

		return $this->evaluateLeaf(leaf: $node, entity: $entity);
	}//end evaluateNode()

	/**
	 * Evaluate an `AND` node's children: true only when the children array
	 * is non-empty and every child is an array node that evaluates true.
	 *
	 * Extracted from {@see evaluateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param mixed $children The node's `children` value.
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return bool
	 */
	private function evaluateAndChildren(mixed $children, array $entity): bool {
		if (is_array($children) === false || $children === []) {
			return false;
		}

		foreach ($children as $child) {
			if (is_array($child) === false) {
				return false;
			}

			if ($this->evaluateNode(node: $child, entity: $entity) === false) {
				return false;
			}
		}

		return true;
	}//end evaluateAndChildren()

	/**
	 * Evaluate an `OR` node's children: true when the children array is
	 * non-empty and at least one array child evaluates true (non-array
	 * children are skipped rather than failing the whole node).
	 *
	 * Extracted from {@see evaluateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param mixed $children The node's `children` value.
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return bool
	 */
	private function evaluateOrChildren(mixed $children, array $entity): bool {
		if (is_array($children) === false || $children === []) {
			return false;
		}

		foreach ($children as $child) {
			if (is_array($child) === false) {
				continue;
			}

			if ($this->evaluateNode(node: $child, entity: $entity) === true) {
				return true;
			}
		}

		return false;
	}//end evaluateOrChildren()

	/**
	 * Evaluate a `NOT` node: negation of its single child.
	 *
	 * Extracted from {@see evaluateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return bool
	 */
	private function evaluateNotChild(array $node, array $entity): bool {
		$children = ($node['children'] ?? []);
		if (is_array($children) === false || isset($children[0]) === false || is_array($children[0]) === false) {
			return false;
		}

		return ($this->evaluateNode(node: $children[0], entity: $entity) === false);
	}//end evaluateNotChild()

	/**
	 * Evaluate one leaf predicate against the entity.
	 *
	 * @param array<string, mixed> $leaf Leaf predicate.
	 * @param array<string, mixed> $entity Entity payload.
	 *
	 * @return bool Truth value.
	 */
	private function evaluateLeaf(array $leaf, array $entity): bool {
		$field = ($leaf['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$operator = (string)($leaf['operator'] ?? 'equals');
		$value = ($leaf['value'] ?? null);
		$actual = ($entity[$field] ?? null);

		$handler = ($this->operatorHandlers()[$operator] ?? null);
		if ($handler === null) {
			return false;
		}

		return $handler($actual, $value);
	}//end evaluateLeaf()

	/**
	 * Operator name → predicate closure map used by {@see evaluateLeaf()}.
	 *
	 * Extracted so the operator dispatch is an O(1) lookup instead of a
	 * 19-branch switch, which is what kept {@see evaluateLeaf()}'s
	 * cyclomatic complexity over threshold; each closure's own logic is
	 * unchanged from the prior switch-case bodies (several delegate to the
	 * small `evaluate*()` helpers below for the cases that had internal
	 * branching).
	 *
	 * @return array<string, callable(mixed, mixed): bool> Operator handlers.
	 */
	private function operatorHandlers(): array {
		return [
			'equals' => fn (mixed $actual, mixed $value): bool => $this->looseEquals(left: $actual, right: $value),
			'notEquals' => fn (mixed $actual, mixed $value): bool => ($this->looseEquals(left: $actual, right: $value) === false),
			'gt' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === 1),
			'greaterThan' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === 1),
			'gte' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) >= 0),
			'greaterThanOrEqual' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) >= 0),
			'lt' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === -1),
			'lessThan' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === -1),
			'lte' => fn (mixed $actual, mixed $value): bool => $this->evaluateLessThanOrEqual(actual: $actual, value: $value),
			'lessThanOrEqual' => fn (mixed $actual, mixed $value): bool => $this->evaluateLessThanOrEqual(actual: $actual, value: $value),
			'before' => fn (mixed $actual, mixed $value): bool => ($this->compareDates(left: $actual, right: $value) === -1),
			'after' => fn (mixed $actual, mixed $value): bool => ($this->compareDates(left: $actual, right: $value) === 1),
			'between' => fn (mixed $actual, mixed $value): bool => $this->evaluateBetween(actual: $actual, value: $value),
			'contains' => fn (mixed $actual, mixed $value): bool => $this->valueContains(haystack: $actual, needle: $value),
			'containsAny' => fn (mixed $actual, mixed $value): bool => $this->evaluateContainsAny(actual: $actual, value: $value),
			'in' => fn (mixed $actual, mixed $value): bool => $this->evaluateIn(actual: $actual, value: $value),
			'notIn' => fn (mixed $actual, mixed $value): bool => $this->evaluateNotIn(actual: $actual, value: $value),
			'isNull' => fn (mixed $actual): bool => ($actual === null),
			'isNotNull' => fn (mixed $actual): bool => ($actual !== null),
		];
	}//end operatorHandlers()

	/**
	 * `lte` / `lessThanOrEqual` predicate.
	 *
	 * @param mixed $actual The entity's field value.
	 * @param mixed $value The rule value.
	 *
	 * @return bool
	 */
	private function evaluateLessThanOrEqual(mixed $actual, mixed $value): bool {
		$cmp = $this->compareNumeric(left: $actual, right: $value);
		return ($cmp === -1 || $cmp === 0);
	}//end evaluateLessThanOrEqual()

	/**
	 * `between` predicate: `$value` must be a 2-element array `[low, high]`.
	 *
	 * @param mixed $actual The entity's field value.
	 * @param mixed $value The rule value.
	 *
	 * @return bool
	 */
	private function evaluateBetween(mixed $actual, mixed $value): bool {
		if (is_array($value) === false || count($value) !== 2) {
			return false;
		}

		$low = $this->compareNumeric(left: $actual, right: $value[0]);
		$high = $this->compareNumeric(left: $actual, right: $value[1]);
		return ($low >= 0 && $high <= 0);
	}//end evaluateBetween()

	/**
	 * `containsAny` predicate: true when `$actual` contains any candidate
	 * in `$value`.
	 *
	 * @param mixed $actual The entity's field value.
	 * @param mixed $value The rule value (expected array of candidates).
	 *
	 * @return bool
	 */
	private function evaluateContainsAny(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $candidate) {
			if ($this->valueContains(haystack: $actual, needle: $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end evaluateContainsAny()

	/**
	 * `in` predicate: true when `$actual` loosely equals any candidate in
	 * `$value`.
	 *
	 * @param mixed $actual The entity's field value.
	 * @param mixed $value The rule value (expected array of candidates).
	 *
	 * @return bool
	 */
	private function evaluateIn(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $candidate) {
			if ($this->looseEquals(left: $actual, right: $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end evaluateIn()

	/**
	 * `notIn` predicate: true when `$actual` loosely equals none of the
	 * candidates in `$value`.
	 *
	 * @param mixed $actual The entity's field value.
	 * @param mixed $value The rule value (expected array of candidates).
	 *
	 * @return bool
	 */
	private function evaluateNotIn(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return true;
		}

		foreach ($value as $candidate) {
			if ($this->looseEquals(left: $actual, right: $candidate) === true) {
				return false;
			}
		}

		return true;
	}//end evaluateNotIn()

	/**
	 * Loose equality with case-insensitive string compare.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 *
	 * @return bool Whether the two values match.
	 */
	private function looseEquals(mixed $left, mixed $right): bool {
		if ($left === null && $right === null) {
			return true;
		}

		if (is_bool($left) === true || is_bool($right) === true) {
			return ((bool)$left === (bool)$right);
		}

		if (is_numeric($left) === true && is_numeric($right) === true) {
			return ((float)$left === (float)$right);
		}

		if (is_scalar($left) === true && is_scalar($right) === true) {
			return (strcasecmp((string)$left, (string)$right) === 0);
		}

		return ($left === $right);
	}//end looseEquals()

	/**
	 * Numeric comparison returning -1, 0, or 1. Returns 0 when either
	 * side is non-numeric so callers fail closed.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 *
	 * @return int -1, 0, or 1.
	 */
	private function compareNumeric(mixed $left, mixed $right): int {
		if (is_numeric($left) === false || is_numeric($right) === false) {
			if (is_string($left) === true && is_string($right) === true) {
				return $left <=> $right;
			}

			return 0;
		}

		$leftFloat = (float)$left;
		$rightFloat = (float)$right;
		if ($leftFloat < $rightFloat) {
			return -1;
		}

		if ($leftFloat > $rightFloat) {
			return 1;
		}

		return 0;
	}//end compareNumeric()

	/**
	 * Parse two date strings and compare them. Returns 0 on parse
	 * failure so callers fail closed.
	 *
	 * @param mixed $left Left date-like value.
	 * @param mixed $right Right date-like value.
	 *
	 * @return int -1, 0, or 1.
	 */
	private function compareDates(mixed $left, mixed $right): int {
		$leftTimestamp = $this->toTimestamp(value: $left);
		$rightTimestamp = $this->toTimestamp(value: $right);
		if ($leftTimestamp === null || $rightTimestamp === null) {
			return 0;
		}

		if ($leftTimestamp < $rightTimestamp) {
			return -1;
		}

		if ($leftTimestamp > $rightTimestamp) {
			return 1;
		}

		return 0;
	}//end compareDates()

	/**
	 * Coerce a value to a unix timestamp. Accepts ISO date strings, a
	 * "N days" / "N days ago" relative expression, or an integer.
	 *
	 * @param mixed $value Date-like value.
	 *
	 * @return int|null Timestamp seconds or null on parse failure.
	 */
	private function toTimestamp(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		$match = [];
		if (preg_match('/^(\d+)\s+days?(\s+ago)?$/i', $value, $match) === 1) {
			$days = (int)$match[1];
			$stamp = (time() - ($days * 86400));
			return $stamp;
		}

		$parsed = strtotime($value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end toTimestamp()

	/**
	 * Substring-or-array-membership check used by `contains` / `containsAny`.
	 *
	 * @param mixed $haystack The actual entity value.
	 * @param mixed $needle The rule value.
	 *
	 * @return bool Whether the haystack contains the needle.
	 */
	private function valueContains(mixed $haystack, mixed $needle): bool {
		if ($haystack === null) {
			return false;
		}

		if (is_array($haystack) === true) {
			foreach ($haystack as $element) {
				if ($this->looseEquals(left: $element, right: $needle) === true) {
					return true;
				}
			}

			return false;
		}

		if (is_scalar($haystack) === false || is_scalar($needle) === false) {
			return false;
		}

		$haystackStr = strtolower((string)$haystack);
		$needleStr = strtolower((string)$needle);
		if ($needleStr === '') {
			return false;
		}

		return (str_contains($haystackStr, $needleStr) === true);
	}//end valueContains()

	/**
	 * Recursively validate a node.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param string $path JSON-pointer-ish breadcrumb.
	 * @param array<string, mixed> $properties Schema properties map.
	 *
	 * @return string|null Error string or null.
	 */
	private function validateNode(array $node, string $path, array $properties): ?string {
		$type = $this->nodeType(node: $node);
		if ($type === 'AND' || $type === 'OR') {
			return $this->validateCompositeNode(node: $node, path: $path, properties: $properties, type: $type);
		}

		if ($type === 'NOT') {
			return $this->validateNotNode(node: $node, path: $path, properties: $properties);
		}

		return $this->validateLeafNode(node: $node, path: $path, properties: $properties);
	}//end validateNode()

	/**
	 * Validate an `AND`/`OR` node: non-empty `children`, each an object,
	 * each recursively valid.
	 *
	 * Extracted from {@see validateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param string $path JSON-pointer-ish breadcrumb.
	 * @param array<string, mixed> $properties Schema properties map.
	 * @param string $type `AND` or `OR` (for the error message).
	 *
	 * @return string|null Error string or null.
	 */
	private function validateCompositeNode(array $node, string $path, array $properties, string $type): ?string {
		$children = ($node['children'] ?? null);
		if (is_array($children) === false || $children === []) {
			return sprintf('%s: composite "%s" node requires non-empty "children".', $path, $type);
		}

		foreach ($children as $index => $child) {
			if (is_array($child) === false) {
				return sprintf('%s.children[%d]: child must be an object.', $path, $index);
			}

			$childError = $this->validateNode(
				node: $child,
				path: $path . '.children[' . $index . ']',
				properties: $properties
			);
			if ($childError !== null) {
				return $childError;
			}
		}

		return null;
	}//end validateCompositeNode()

	/**
	 * Validate a `NOT` node: exactly one child, which must be an object
	 * and recursively valid.
	 *
	 * Extracted from {@see validateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param string $path JSON-pointer-ish breadcrumb.
	 * @param array<string, mixed> $properties Schema properties map.
	 *
	 * @return string|null Error string or null.
	 */
	private function validateNotNode(array $node, string $path, array $properties): ?string {
		$children = ($node['children'] ?? null);
		if (is_array($children) === false || count($children) !== 1) {
			return sprintf('%s: NOT node requires exactly one child.', $path);
		}

		$child = $children[0];
		if (is_array($child) === false) {
			return sprintf('%s.children[0]: child must be an object.', $path);
		}

		return $this->validateNode(node: $child, path: $path . '.children[0]', properties: $properties);
	}//end validateNotNode()

	/**
	 * Validate a leaf predicate node: `field` declared on the schema,
	 * `operator` supported and valid for the field's type, and (unless a
	 * null-check operator) a coercible `value`.
	 *
	 * Extracted from {@see validateNode()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $node Tree node.
	 * @param string $path JSON-pointer-ish breadcrumb.
	 * @param array<string, mixed> $properties Schema properties map.
	 *
	 * @return string|null Error string or null.
	 */
	private function validateLeafNode(array $node, string $path, array $properties): ?string {
		$field = ($node['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return sprintf('%s: leaf predicate requires non-empty "field".', $path);
		}

		if (array_key_exists($field, $properties) === false) {
			return sprintf('%s: field "%s" is not declared on the entity schema.', $path, $field);
		}

		$operator = ($node['operator'] ?? null);
		$allowedTypes = $this->resolveAllowedTypesForOperator(operator: $operator);
		if ($allowedTypes === null) {
			return sprintf('%s: operator "%s" is not supported.', $path, (string)$operator);
		}

		$fieldType = $this->propertyType(property: $properties[$field]);
		if (in_array($fieldType, $allowedTypes, true) === false) {
			return sprintf(
				'%s: operator "%s" is not valid for field "%s" of type "%s".',
				$path,
				(string)$operator,
				$field,
				$fieldType
			);
		}

		if (in_array($operator, ['isNull', 'isNotNull'], true) === true) {
			return null;
		}

		if (array_key_exists('value', $node) === false) {
			return sprintf('%s: operator "%s" requires a "value".', $path, (string)$operator);
		}

		if ($this->isValueCoercible(value: $node['value'], fieldType: $fieldType, operator: (string)$operator) === false) {
			return sprintf(
				'%s: value for field "%s" is not coercible to type "%s".',
				$path,
				$field,
				$fieldType
			);
		}

		return null;
	}//end validateLeafNode()

	/**
	 * Resolve the JSON-schema types an operator is valid for, or null when
	 * the operator is not a recognised, supported operator.
	 *
	 * Extracted from {@see validateLeafNode()} so the `is_string($operator)`
	 * guard and the `OPERATOR_TYPE_MATRIX` lookup collapse into one branch
	 * instead of two (`is_string(...) === false || isset(...) === false`);
	 * behaviour is unchanged.
	 *
	 * @param mixed $operator The raw operator value.
	 *
	 * @return array<int, string>|null Allowed JSON-schema types, or null when unsupported.
	 */
	private function resolveAllowedTypesForOperator(mixed $operator): ?array {
		if (is_string($operator) === false) {
			return null;
		}

		return (self::OPERATOR_TYPE_MATRIX[$operator] ?? null);
	}//end resolveAllowedTypesForOperator()

	/**
	 * Determine whether a value is coercible to the field's declared type.
	 *
	 * @param mixed $value The raw rule value.
	 * @param string $fieldType JSON-schema type.
	 * @param string $operator Operator (drives array-vs-scalar shape).
	 *
	 * @return bool True when coercion succeeds.
	 */
	private function isValueCoercible(mixed $value, string $fieldType, string $operator): bool {
		if (in_array($operator, ['in', 'notIn', 'containsAny', 'between'], true) === true) {
			if (is_array($value) === false) {
				return false;
			}

			if ($operator === 'between' && count($value) !== 2) {
				return false;
			}

			foreach ($value as $element) {
				if ($this->isScalarCoercible(value: $element, fieldType: $fieldType) === false) {
					return false;
				}
			}

			return true;
		}

		return $this->isScalarCoercible(value: $value, fieldType: $fieldType);
	}//end isValueCoercible()

	/**
	 * Determine whether one scalar value coerces to the field type.
	 *
	 * @param mixed $value The raw value.
	 * @param string $fieldType JSON-schema type.
	 *
	 * @return bool True when coercion succeeds.
	 */
	private function isScalarCoercible(mixed $value, string $fieldType): bool {
		if ($value === null) {
			return true;
		}

		switch ($fieldType) {
			case 'integer':
				return $this->isIntegerCoercible(value: $value);
			case 'number':
				return $this->isNumberCoercible(value: $value);
			case 'boolean':
				return $this->isBooleanCoercible(value: $value);
			case 'array':
				return is_array($value);
			case 'string':
			default:
				return (is_scalar($value) === true);
		}//end switch
	}//end isScalarCoercible()

	/**
	 * `integer` coercibility: an int, or a string of digits (optional
	 * leading `-`).
	 *
	 * Extracted from {@see isScalarCoercible()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param mixed $value The raw (non-null) value.
	 *
	 * @return bool
	 */
	private function isIntegerCoercible(mixed $value): bool {
		if (is_int($value) === true) {
			return true;
		}

		return (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1);
	}//end isIntegerCoercible()

	/**
	 * `number` coercibility: an int/float, or a numeric string.
	 *
	 * Extracted from {@see isScalarCoercible()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param mixed $value The raw (non-null) value.
	 *
	 * @return bool
	 */
	private function isNumberCoercible(mixed $value): bool {
		if (is_int($value) === true || is_float($value) === true) {
			return true;
		}

		return (is_string($value) === true && is_numeric($value) === true);
	}//end isNumberCoercible()

	/**
	 * `boolean` coercibility: a bool, a `true`/`false`/`0`/`1` string
	 * (case-insensitive), or the int `0`/`1`.
	 *
	 * Extracted from {@see isScalarCoercible()} so it stays within the
	 * complexity budget; behaviour is unchanged.
	 *
	 * @param mixed $value The raw (non-null) value.
	 *
	 * @return bool
	 */
	private function isBooleanCoercible(mixed $value): bool {
		if (is_bool($value) === true) {
			return true;
		}

		if (is_string($value) === true && in_array(strtolower($value), ['true', 'false', '0', '1'], true) === true) {
			return true;
		}

		return (is_int($value) === true && ($value === 0 || $value === 1));
	}//end isBooleanCoercible()

	/**
	 * Return the canonical node type — `AND`, `OR`, `NOT`, or `LEAF`.
	 *
	 * @param array<string, mixed> $node Tree node.
	 *
	 * @return string The node type.
	 */
	private function nodeType(array $node): string {
		$declared = ($node['type'] ?? null);
		if (is_string($declared) === true) {
			$upper = strtoupper($declared);
			if (in_array($upper, ['AND', 'OR', 'NOT'], true) === true) {
				return $upper;
			}
		}

		return 'LEAF';
	}//end nodeType()

	/**
	 * Resolve a schema property's JSON-schema type, defaulting to string.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return string Type string.
	 */
	private function propertyType(mixed $property): string {
		if (is_array($property) === true && isset($property['type']) === true && is_string($property['type']) === true) {
			return $property['type'];
		}

		return 'string';
	}//end propertyType()

	/**
	 * Resolve the entityType's schema properties via OpenRegister.
	 *
	 * Looks up the schema slug for the requested entityType, then fetches
	 * the full Schema entity through OpenRegister's SchemaMapper so the
	 * rule validator can read each property's declared `type`. Returns
	 * null when the entityType is unknown or OpenRegister is unreachable
	 * — callers translate that into a validation error rather than
	 * raising.
	 *
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return array<string, mixed>|null Properties map, or null when the
	 *                                   schema is not resolvable.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	protected function resolveSchemaProperties(string $entityType): ?array {
		$schemaSlug = $this->resolveSchemaSlug(entityType: $entityType);
		if ($schemaSlug === '') {
			return null;
		}

		$schemaMapper = $this->getSchemaMapper();
		if ($schemaMapper === null) {
			return null;
		}

		try {
			$schema = $schemaMapper->find(
				id: $schemaSlug,
				published: null,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.resolveSchemaProperties: schema lookup failed',
				['entityType' => $entityType, 'slug' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($schema) === false || method_exists($schema, 'getProperties') === false) {
			return null;
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return null;
		}

		return $properties;
	}//end resolveSchemaProperties()

	/**
	 * Resolve the schema slug for the given entityType.
	 *
	 * Resolution order:
	 * 1. The Pipelinq app config key (`contact_schema` /
	 *    `customer_schema` / `client_schema`) if it is set.
	 * 2. A sensible default — `contact` for contacts, `client` for
	 *    customers — matching the pipelinq register declarations.
	 *
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return string The resolved schema slug, or empty when unknown.
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	protected function resolveSchemaSlug(string $entityType): string {
		$entityType = strtolower($entityType);
		$candidateKeys = [];
		if ($entityType === 'contact') {
			$candidateKeys = ['contact_schema'];
		} elseif ($entityType === 'customer' || $entityType === 'client') {
			$candidateKeys = ['customer_schema', 'client_schema'];
		}

		foreach ($candidateKeys as $key) {
			$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($slug !== '') {
				return $slug;
			}
		}

		if ($entityType === 'contact') {
			return self::DEFAULT_CONTACT_SCHEMA_SLUG;
		}

		if ($entityType === 'customer' || $entityType === 'client') {
			return self::DEFAULT_CUSTOMER_SCHEMA_SLUG;
		}

		return '';
	}//end resolveSchemaSlug()

	/**
	 * Load one Segment payload by UUID or slug.
	 *
	 * @param string $segmentId The Segment UUID or slug.
	 *
	 * @return array<string, mixed>|null The Segment as an array or null.
	 */
	private function loadSegment(string $segmentId): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getSegmentSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $segmentId,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.loadSegment: not found',
				['segmentId' => $segmentId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadSegment()

	/**
	 * Load every entity of the given type for in-memory rule evaluation.
	 *
	 * Intentionally returns ALL matching objects (no filters) — the
	 * SegmentService does the predicate evaluation in PHP since
	 * OpenRegister's filter DSL does not natively express AND/OR rule
	 * trees. Member-09 tests will pin the upper bound on the seed
	 * dataset.
	 *
	 * @param string $entityType "contact" or "customer".
	 *
	 * @return array<int, array<string, mixed>> Entity payloads.
	 */
	private function loadEntitiesForType(string $entityType): array {
		$register = $this->getRegisterSlug();
		$schema = $this->resolveSchemaSlug(entityType: $entityType);
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.loadEntitiesForType: findAll failed',
				['entityType' => $entityType, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$entities = [];
		foreach (($rows ?? []) as $row) {
			$entities[] = $this->toArray(value: $row);
		}

		return $entities;
	}//end loadEntitiesForType()

	/**
	 * Resolve the Segment schema slug from app config.
	 *
	 * @return string Schema slug.
	 */
	private function getSegmentSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'segment_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SEGMENT_SCHEMA_SLUG;
	}//end getSegmentSchemaSlug()

	/**
	 * Public accessor for the Segment schema slug.
	 *
	 * Used by BlastService (member 04 + 06) to validate that a referenced
	 * `segmentId` points at an existing Segment row before it persists a
	 * draft Blast — without forcing BlastService to re-read the same
	 * `segment_schema` app-config key.
	 *
	 * @return string Schema slug.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function getSegmentSchemaSlugPublic(): string {
		return $this->getSegmentSchemaSlug();
	}//end getSegmentSchemaSlugPublic()

	/**
	 * Resolve the register slug from app config.
	 *
	 * @return string Register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Extract the rule tree from a Segment payload.
	 *
	 * @param array<string, mixed> $segment The Segment payload.
	 *
	 * @return array<string, mixed>|null Rule tree or null if missing.
	 */
	private function extractRules(array $segment): ?array {
		$rules = ($segment['rules'] ?? null);
		if (is_string($rules) === true && $rules !== '') {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		if (is_array($rules) === true) {
			return $rules;
		}

		return null;
	}//end extractRules()

	/**
	 * Extract the entityType from a Segment payload.
	 *
	 * @param array<string, mixed> $segment The Segment payload.
	 *
	 * @return string|null entityType or null if missing.
	 */
	private function extractEntityType(array $segment): ?string {
		$entityType = ($segment['entityType'] ?? null);
		if (is_string($entityType) === true && $entityType !== '') {
			return $entityType;
		}

		return null;
	}//end extractEntityType()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Resolve the estimateSize TTL from app config, with a 3600s default.
	 *
	 * @return int TTL seconds.
	 */
	private function getEstimateTtl(): int {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'segment.estimate_ttl_seconds',
			(string)self::DEFAULT_ESTIMATE_TTL_SECONDS
		);
		if ($configured === '' || is_numeric($configured) === false) {
			return self::DEFAULT_ESTIMATE_TTL_SECONDS;
		}

		$ttl = (int)$configured;
		if ($ttl < 0) {
			return self::DEFAULT_ESTIMATE_TTL_SECONDS;
		}

		return $ttl;
	}//end getEstimateTtl()

	/**
	 * Build the distributed cache used for estimateSize, falling back
	 * to the local cache if distributed is unavailable.
	 *
	 * @return ICache|null The cache, or null on initialisation failure.
	 */
	private function getEstimateCache(): ?ICache {
		try {
			if ($this->cacheFactory->isAvailable() === true) {
				return $this->cacheFactory->createDistributed('pipelinq_segment_estimate');
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.getEstimateCache: distributed cache unavailable',
				['exception' => $e->getMessage()]
			);
		}

		try {
			return $this->cacheFactory->createLocal('pipelinq_segment_estimate');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.getEstimateCache: local cache unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getEstimateCache()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService, or null when OpenRegister is
	 *                     not loaded.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Resolve the OpenRegister SchemaMapper lazily.
	 *
	 * @return object|null SchemaMapper, or null when OpenRegister is not
	 *                     loaded.
	 */
	private function getSchemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.getSchemaMapper: SchemaMapper unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getSchemaMapper()

	/**
	 * Reference field retained so the schema-map cache hint stays
	 * touched in case a sub-class wants to override the mapping.
	 *
	 * @return SchemaMapService Schema-map helper.
	 */
	protected function schemaMapService(): SchemaMapService {
		return $this->schemaMapService;
	}//end schemaMapService()
}//end class
