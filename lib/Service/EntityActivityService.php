<?php

/**
 * Pipelinq EntityActivityService.
 *
 * Read-only aggregation of activity instances (contactmomenten and the
 * platform-managed `notes` field) per Pipelinq entity, exposed via the
 * `/api/activity/{entityType}/{entityId}` REST endpoint.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Builds the merged, paginated activity feed for an entity.
 *
 * Mappers are never touched directly (ADR-003-backend); all OpenRegister
 * access goes through `ObjectService::findAll()` / `ObjectService::find()`.
 *
 * The class is decoupled from the pre-existing `ActivityService` (which
 * publishes to the Nextcloud Activity Stream) and from
 * `ActivityTimelineService` (which exposes the multi-source
 * `/api/timeline` endpoint). It serves a single purpose: provide the
 * narrow v1 wire format consumed by `CommunicationHistory.vue`.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is a flat pipeline of
 *  guard-heavy normalisers (OR rows and note payloads arrive in several shapes, each
 *  branch a defensive coercion); the branches sum past the threshold but no single
 *  method is complex, and splitting the pipeline would only relocate the guards.
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-2
 */
class EntityActivityService {
	/**
	 * Entity types the endpoint is allowed to expose. Anything outside this
	 * list is rejected by the controller with a 400.
	 */
	private const ALLOWED_ENTITY_TYPES = [
		'client',
		'contact',
		'lead',
		'request',
	];

	/**
	 * Activity-type filter values understood by the API.
	 */
	private const ALLOWED_TYPES = [
		'all',
		'notes',
		'contactmomenten',
	];

	/**
	 * Default page size.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Upper cap on page size to prevent pathological pagination.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * Per-schema ceiling when collecting contactmomenten; merging and
	 * pagination happen in PHP so the per-schema query must be bounded.
	 */
	private const PER_SCHEMA_CEILING = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService Resolver for the unified `ticket`
	 *                                     supertype — both the contactmoment
	 *                                     source and the `request` entity are
	 *                                     tickets after unify-ticket-supertype.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether an entity type is one the endpoint may expose.
	 *
	 * Exposed as a public helper so the controller can validate without
	 * duplicating the allowlist.
	 *
	 * @param string $entityType The entity type to validate.
	 *
	 * @return bool True when the type is allowed.
	 *
	 * @spec openspec/changes/entity-notes/tasks.md#task-2
	 */
	public function isAllowedEntityType(string $entityType): bool {
		return in_array($entityType, self::ALLOWED_ENTITY_TYPES, true);
	}//end isAllowedEntityType()

	/**
	 * Build the activity feed for an entity.
	 *
	 * @param string $entityType The entity type
	 *                           (`client|contact|lead|request`).
	 * @param string $entityId The OR object UUID.
	 * @param string $type The activity filter
	 *                     (`all|notes|contactmomenten`).
	 * @param int $page 1-based page number.
	 * @param int $limit Page size; clamped to
	 *                   `[1, MAX_LIMIT]`.
	 *
	 * @return array{total:int,page:int,pages:int,results:array<int,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When the entity type is unknown.
	 *
	 * @spec openspec/changes/entity-notes/tasks.md#task-2
	 */
	public function getActivity(
		string $entityType,
		string $entityId,
		string $type,
		int $page,
		int $limit,
	): array {
		if ($this->isAllowedEntityType(entityType: $entityType) === false) {
			throw new InvalidArgumentException('Invalid entity type');
		}

		$filterType = $this->normaliseTypeFilter(rawType: $type);
		$window = new ActivityPage(page: max(1, $page), limit: $this->normaliseLimit(rawLimit: $limit));

		$registerId = $this->appConfig->getValueString(
			Application::APP_ID,
			'register',
			''
		);
		if ($registerId === '') {
			return $this->emptyResult(page: $window->page);
		}

		$items = $this->collectActivity(
			query: new EntityActivityQuery(registerId: $registerId, entityType: $entityType, entityId: $entityId),
			filterType: $filterType
		);

		return $this->paginate(items: $this->newestFirst(items: $items), window: $window);
	}//end getActivity()

	/**
	 * Collect the activity items the type filter asks for: contactmomenten
	 * first, then notes.
	 *
	 * @param EntityActivityQuery $query      The entity and register.
	 * @param string              $filterType One of `all|notes|contactmomenten`.
	 *
	 * @return array<int,array<string,mixed>> The unsorted items.
	 */
	private function collectActivity(EntityActivityQuery $query, string $filterType): array {
		$items = [];

		if ($filterType === 'all' || $filterType === 'contactmomenten') {
			$items = array_merge($items, $this->collectContactmomenten(query: $query));
		}

		if ($filterType === 'all' || $filterType === 'notes') {
			$items = array_merge($items, $this->collectNotes(query: $query));
		}

		return $items;
	}//end collectActivity()

	/**
	 * Sort activity items newest first by their timestamp.
	 *
	 * @param array<int,array<string,mixed>> $items The items.
	 *
	 * @return array<int,array<string,mixed>> The sorted items.
	 */
	private function newestFirst(array $items): array {
		usort(
			$items,
			static function (array $left, array $right): int {
				return strcmp(
					(string)($right['timestamp'] ?? ''),
					(string)($left['timestamp'] ?? '')
				);
			}
		);

		return $items;
	}//end newestFirst()

	/**
	 * Normalise the user-supplied `type` filter.
	 *
	 * Anything outside the allowlist is coerced to `all` so a malformed
	 * filter never widens or narrows access in unexpected ways.
	 *
	 * @param string $rawType The raw `type` request parameter.
	 *
	 * @return string One of `all|notes|contactmomenten`.
	 */
	private function normaliseTypeFilter(string $rawType): string {
		$candidate = strtolower(trim($rawType));
		if (in_array($candidate, self::ALLOWED_TYPES, true) === false) {
			return 'all';
		}

		return $candidate;
	}//end normaliseTypeFilter()

	/**
	 * Clamp the page-size request parameter into `[1, MAX_LIMIT]`.
	 *
	 * @param int $rawLimit The raw `_limit` request parameter.
	 *
	 * @return int The clamped limit.
	 */
	private function normaliseLimit(int $rawLimit): int {
		if ($rawLimit <= 0) {
			return self::DEFAULT_LIMIT;
		}

		if ($rawLimit > self::MAX_LIMIT) {
			return self::MAX_LIMIT;
		}

		return $rawLimit;
	}//end normaliseLimit()

	/**
	 * Query and normalise the contactmomenten linked to an entity.
	 *
	 * Contactmomenten live in the unified `ticket` schema with
	 * `ticketType=contactmoment` (unify-ticket-supertype); the wire shape of the
	 * returned items is unchanged, but it is now sourced from the ticket field
	 * names (title / description / occurredAt / assignee).
	 *
	 * @param EntityActivityQuery $query The entity and register.
	 *
	 * @return array<int,array<string,mixed>> Normalised activity items.
	 */
	private function collectContactmomenten(EntityActivityQuery $query): array {
		$items = [];
		foreach ($this->contactmomentObjects(query: $query) as $object) {
			$items[] = $this->contactmomentItem(object: $object);
		}

		return $items;
	}//end collectContactmomenten()

	/**
	 * The contactmoment tickets linked to an entity; none when the ticket
	 * schema is unconfigured or the entity type has no link field.
	 *
	 * @param EntityActivityQuery $query The entity and register.
	 *
	 * @return array<int,array<string,mixed>> The raw object arrays.
	 */
	private function contactmomentObjects(EntityActivityQuery $query): array {
		$schemaId = $this->ticketService->getSchemaId();
		if ($schemaId === '') {
			return [];
		}

		$linkField = $this->contactmomentLinkField(entityType: $query->entityType);
		if ($linkField === null) {
			// No direct link field on contactmoment for this entity type
			// (e.g. lead/contact); skip rather than 500.
			return [];
		}

		$filters = ['ticketType' => TicketService::TYPE_CONTACTMOMENT, $linkField => $query->entityId];
		return $this->queryObjects(filters: $query->schemaFilters(schemaId: $schemaId, filters: $filters));
	}//end contactmomentObjects()

	/**
	 * One contactmoment ticket as an activity item.
	 *
	 * @param array<string,mixed> $object The ticket.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function contactmomentItem(array $object): array {
		return [
			'type' => 'interaction',
			'id' => (string)($object['id'] ?? $object['uuid'] ?? ''),
			'subject' => (string)($object['title'] ?? ''),
			'channel' => (string)($object['channel'] ?? ''),
			// The direction the contact ran in, so the panel rendering this
			// feed can facet on it rather than digging through the free-form
			// channelMetadata envelope it used to live in.
			'direction' => (string)($object['direction'] ?? ''),
			'agent' => (string)($object['assignee'] ?? ''),
			'timestamp' => (string)($object['occurredAt'] ?? ''),
			'summary' => (string)($object['description'] ?? ''),
		];
	}//end contactmomentItem()

	/**
	 * Map an entity type to the ticket field that holds the back-reference UUID
	 * on a contactmoment ticket. Returns null when no native back-reference
	 * exists so the caller can omit the source rather than emit a junk filter.
	 *
	 * The request back-reference is the ticket field `parentTicket` (formerly
	 * contactmoment.request).
	 *
	 * @param string $entityType The validated entity type.
	 *
	 * @return string|null The ticket property name, or null.
	 */
	private function contactmomentLinkField(string $entityType): ?string {
		return match ($entityType) {
			'client' => 'client',
			'request' => 'parentTicket',
			default => null,
		};
	}//end contactmomentLinkField()

	/**
	 * Fetch the entity's notes (stored on the OR built-in `notes` field on
	 * the entity object) and expand them into activity items.
	 *
	 * @param EntityActivityQuery $query The entity and register.
	 *
	 * @return array<int,array<string,mixed>> Normalised activity items.
	 */
	private function collectNotes(EntityActivityQuery $query): array {
		$items = [];
		foreach ($this->notesOf(query: $query) as $note) {
			if (is_array($note) === false) {
				continue;
			}

			$items[] = $this->noteItem(note: $note);
		}

		return $items;
	}//end collectNotes()

	/**
	 * The raw `notes` of an entity; none when its schema is unconfigured,
	 * it cannot be loaded or it carries no notes list.
	 *
	 * @param EntityActivityQuery $query The entity and register.
	 *
	 * @return array<mixed> The raw notes.
	 */
	private function notesOf(EntityActivityQuery $query): array {
		$schemaId = $this->entitySchemaId(entityType: $query->entityType);
		if ($schemaId === '') {
			return [];
		}

		$object = $this->findObject(entityId: $query->entityId, registerId: $query->registerId, schemaId: $schemaId);
		if ($object === null) {
			return [];
		}

		$rawNotes = ($object['notes'] ?? null);
		if (is_array($rawNotes) === false) {
			return [];
		}

		return $rawNotes;
	}//end notesOf()

	/**
	 * One note as an activity item.
	 *
	 * @param array<string,mixed> $note The note.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function noteItem(array $note): array {
		return [
			'type' => 'note',
			'id' => (string)($note['id'] ?? ''),
			'subject' => (string)($note['title'] ?? ''),
		] + $this->noteAuthorship(note: $note);
	}//end noteItem()

	/**
	 * Who wrote a note, when and what, read from the field names the note
	 * sources use.
	 *
	 * @param array<string,mixed> $note The note.
	 *
	 * @return array{agent:string,timestamp:string,summary:string} The fields.
	 */
	private function noteAuthorship(array $note): array {
		return [
			'agent' => (string)(
				$note['author'] ?? $note['user'] ?? ''
			),
			'timestamp' => (string)(
				$note['createdAt'] ?? $note['timestamp'] ?? ''
			),
			'summary' => (string)(
				$note['message'] ?? $note['content'] ?? $note['text'] ?? ''
			),
		];
	}//end noteAuthorship()

	/**
	 * Resolve the schema id that stores an entity type's objects.
	 *
	 * `client|contact|lead` still map onto their own `<type>_schema` config key.
	 * `request` is a subtype of the unified `ticket` schema after
	 * unify-ticket-supertype, so it resolves through TicketService instead.
	 *
	 * @param string $entityType The validated entity type.
	 *
	 * @return string The schema id, or '' when unconfigured.
	 */
	private function entitySchemaId(string $entityType): string {
		if ($entityType === 'request') {
			return $this->ticketService->getSchemaId();
		}

		return $this->appConfig->getValueString(
			Application::APP_ID,
			$entityType . '_schema',
			''
		);
	}//end entitySchemaId()

	/**
	 * Query a single schema using equality filters, capped by
	 * `PER_SCHEMA_CEILING`.
	 *
	 * @param array<string,mixed> $filters Equality filters, register and schema first.
	 *
	 * @return array<int,array<string,mixed>> The raw object arrays.
	 */
	private function queryObjects(array $filters): array {
		try {
			$objectService = $this->getObjectService();
			$params = [
				'filters' => $filters,
				'limit' => self::PER_SCHEMA_CEILING,
			];

			$results = $objectService->findAll($params);
			return $this->normaliseResults(results: $results);
		} catch (Throwable $e) {
			$this->logQueryFailure(schemaId: (string)$filters['schema'], error: $e);
			return [];
		}//end try
	}//end queryObjects()

	/**
	 * Log a schema query that failed.
	 *
	 * @param string    $schemaId The schema id.
	 * @param Throwable $error    What the query threw.
	 *
	 * @return void
	 */
	private function logQueryFailure(string $schemaId, Throwable $error): void {
		$this->logger->error(
			'EntityActivityService: failed to query schema',
			[
				'exception' => $error->getMessage(),
				'schemaId' => $schemaId,
			]
		);
	}//end logQueryFailure()

	/**
	 * Find a single object by UUID, scoped to the given register/schema.
	 *
	 * @param string $entityId The OR object UUID.
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 *
	 * @return array<string,mixed>|null The object payload, or null.
	 */
	private function findObject(
		string $entityId,
		string $registerId,
		string $schemaId,
	): ?array {
		try {
			$objectService = $this->getObjectService();
			$entity = $objectService->find(
				id: $entityId,
				options: new ObjectFindOptions(register: $registerId, schema: $schemaId)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'EntityActivityService: failed to load entity for notes',
				[
					'exception' => $e->getMessage(),
					'entityId' => $entityId,
				]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true
			&& method_exists($entity, 'getObject') === true
		) {
			$payload = $entity->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return null;
	}//end findObject()

	/**
	 * Coerce the OpenRegister `findAll` result into a list of plain arrays.
	 *
	 * @param mixed $results The raw findAll return value.
	 *
	 * @return array<int,array<string,mixed>> The plain arrays.
	 */
	private function normaliseResults(mixed $results): array {
		if (is_array($results) === false) {
			return [];
		}

		$output = [];
		foreach ($results as $item) {
			if (is_array($item) === true) {
				$output[] = $item;
				continue;
			}

			if (is_object($item) === false) {
				continue;
			}

			if (method_exists($item, 'getObject') === true) {
				$payload = $item->getObject();
				if (is_array($payload) === true) {
					if (isset($payload['id']) === false
						&& method_exists($item, 'getUuid') === true
					) {
						$payload['id'] = $item->getUuid();
					}

					$output[] = $payload;
					continue;
				}
			}

			$output[] = (array)$item;
		}//end foreach

		return $output;
	}//end normaliseResults()

	/**
	 * Slice the sorted items list into the requested page envelope.
	 *
	 * @param array<int,array<string,mixed>> $items  The sorted activity items.
	 * @param ActivityPage                   $window The page asked for.
	 *
	 * @return array{total:int,page:int,pages:int,results:array<int,array<string,mixed>>}
	 */
	private function paginate(array $items, ActivityPage $window): array {
		$total = count($items);
		$pages = $this->pageCount(total: $total, limit: $window->limit);

		$page = min($window->page, $pages);
		$start = (($page - 1) * $window->limit);
		if ($start < 0) {
			$start = 0;
		}

		return [
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'results' => array_values(array_slice($items, $start, $window->limit)),
		];
	}//end paginate()

	/**
	 * The number of pages a feed fills: one for an empty feed.
	 *
	 * @param int $total The number of items.
	 * @param int $limit Page size.
	 *
	 * @return int The page count.
	 */
	private function pageCount(int $total, int $limit): int {
		$pages = 1;
		if ($total !== 0) {
			$pages = (int)ceil($total / $limit);
		}

		return $pages;
	}//end pageCount()

	/**
	 * Build the response shape for an empty result set (no register
	 * configured, no items, etc.).
	 *
	 * @param int $page The 1-based page number to echo back.
	 *
	 * @return array{total:int,page:int,pages:int,results:array<int,array<string,mixed>>}
	 */
	private function emptyResult(int $page): array {
		return [
			'total' => 0,
			'page' => max(1, $page),
			'pages' => 1,
			'results' => [],
		];
	}//end emptyResult()

	/**
	 * Resolve the OpenRegister `ObjectService` through the DI container.
	 *
	 * @return object The ObjectService instance.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
