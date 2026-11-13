<?php

/**
 * Pipelinq ActivityTimelineService.
 *
 * Service that aggregates CRM activity instances (contactmomenten, tasks,
 * emailLinks, calendarLinks, notes, WhatsApp/SMS messages) for an entity into
 * a unified timeline, and
 * provides worklog read/create operations stored as contactmomenten with
 * channel='worklog'.
 *
 * Since unify-ticket-supertype the contactmoment source is not a schema of its
 * own: contactmomenten (and therefore worklogs) are rows of the unified `ticket`
 * schema carrying `ticketType=contactmoment`, resolved through TicketService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
 * @spec openspec/changes/archive/2026-05-11-activity-timeline/tasks.md#task-1
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Aggregates CRM activity data from multiple OpenRegister schemas.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/archive/2026-05-11-activity-timeline/tasks.md#task-1
 */
class ActivityTimelineService {
	/**
	 * Maximum allowed page size for list endpoints.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * Default page size for list endpoints.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 * @param IUserSession $userSession The current user session.
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService Resolver for the unified `ticket` supertype —
	 *                                     contactmomenten (incl. worklogs) are tickets with
	 *                                     `ticketType=contactmoment`, not their own schema.
	 * @param ActivityTimelineReader $reader Reads the raw rows of every source.
	 * @param ActivityItemNormalizer $itemNormalizer Turns a source row into an activity item.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private TicketService $ticketService,
		private readonly ActivityTimelineReader $reader,
		private readonly ActivityItemNormalizer $itemNormalizer,
	) {
	}//end __construct()

	/**
	 * Read the configured register and schema IDs.
	 *
	 * `contactmoment` is not a schema of its own: since unify-ticket-supertype
	 * it is `ticket` narrowed by `ticketType`, resolved via TicketService.
	 *
	 * Fails closed: '' means the source MUST NOT be queried — ObjectService
	 * skips setRegister()/setSchema() on '', leaving the request's leftover
	 * context. `register` is logged when unset; optional schema ids are not,
	 * as collectActivities() drops them (a supported degraded mode).
	 *
	 * @return array<string,string> Map of source-key => value.
	 */
	private function getConfig(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$task = $this->appConfig->getValueString(AppIdentity::APP_ID, 'task_schema', '');
		$emailLink = $this->appConfig->getValueString(AppIdentity::APP_ID, 'emailLink_schema', '');
		$calendarLink = $this->appConfig->getValueString(AppIdentity::APP_ID, 'calendarLink_schema', '');
		if ($register === '') {
			$this->logger->warning('Pipelinq: "register" is not configured; the activity timeline is refused, not run unscoped');
		}

		return [
			'register' => $register,
			'interaction' => $this->ticketService->getSchemaId(),
			'task' => $task,
			'emailLink' => $emailLink,
			'calendarLink' => $calendarLink,
		];
	}//end getConfig()

	/**
	 * Build the merged, sorted, paginated activity timeline for an entity.
	 *
	 * @param string $entityType The entity type (client|request|lead|contact).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $params Request parameters: from, to, types[], _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
	 */
	public function getTimeline(string $entityType, string $entityId, array $params): array {
		return $this->timeline(entity: new TimelineEntity(entityType: $entityType, entityId: $entityId), params: $params);
	}//end getTimeline()

	/**
	 * Build the merged, sorted, paginated activity timeline for an entity.
	 *
	 * @param TimelineEntity      $entity The entity.
	 * @param array<string,mixed> $params Request parameters: from, to, types[], _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
	 */
	public function timeline(TimelineEntity $entity, array $params): array {
		$pageRequest = $this->pageRequest(params: $params);

		// If no register is configured, return an empty timeline (do not raise).
		$config = $this->getConfig();
		if ($config['register'] === '') {
			return $this->emptyResult(page: $pageRequest->page);
		}

		$run = new TimelineRun(entity: $entity, filter: $this->timelineFilter(params: $params));
		$merged = $this->collectActivities(run: $run, config: $config);

		// Sort by date descending (newest first).
		usort(
			$merged,
			static function (array $left, array $right): int {
				return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
			}
		);

		return (new TimelinePages())->timelinePage(merged: $merged, pageRequest: $pageRequest);
	}//end timeline()

	/**
	 * The page and the clamped page size a request asks for.
	 *
	 * @param array<string,mixed> $params Request parameters: _page, _limit.
	 *
	 * @return PageRequest The page request.
	 */
	private function pageRequest(array $params): PageRequest {
		return new PageRequest(
			page: max(1, (int)($params['_page'] ?? 1)),
			limit: $this->normaliseLimit(rawLimit: ($params['_limit'] ?? null))
		);
	}//end pageRequest()

	/**
	 * The types and date bounds a request narrows the timeline to.
	 *
	 * @param array<string,mixed> $params Request parameters: types[], from, to.
	 *
	 * @return TimelineFilter The filter.
	 */
	private function timelineFilter(array $params): TimelineFilter {
		$types = $this->normaliseTypes(rawTypes: ($params['types'] ?? null));
		[$from, $to] = $this->extractDateBounds(params: $params);

		return new TimelineFilter(types: $types, bounds: new DateBounds(from: $from, to: $to));
	}//end timelineFilter()

	/**
	 * Clamp a raw limit param into the [1, MAX_LIMIT] range.
	 *
	 * @param mixed $rawLimit The raw `_limit` request param.
	 *
	 * @return int The clamped page size.
	 */
	private function normaliseLimit(mixed $rawLimit): int {
		$limit = (int)($rawLimit ?? self::DEFAULT_LIMIT);
		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ($limit > self::MAX_LIMIT) {
			$limit = self::MAX_LIMIT;
		}

		return $limit;
	}//end normaliseLimit()

	/**
	 * Query every configured schema and the extra sources, and merge the
	 * normalized activities for an entity.
	 *
	 * @param TimelineRun         $run    The entity and the filter.
	 * @param array<string,mixed> $config The resolved register/schema config.
	 *
	 * @return array<int,array<string,mixed>> The merged, unsorted activities.
	 */
	private function collectActivities(TimelineRun $run, array $config): array {
		$merged = $this->schemaActivities(run: $run, config: $config);

		return $this->withoutDuplicateEvents(
			activities: array_merge($merged, $this->extraSourceActivities(run: $run, registerId: $config['register']))
		);
	}//end collectActivities()

	/**
	 * Drop calendar items whose event UID another item already shows.
	 *
	 * The calendar leaf's item wins: it is read live, the others may be stale
	 * copies. Items without a UID are never dropped.
	 *
	 * @param array<int,array<string,mixed>> $activities The merged items.
	 *
	 * @return array<int,array<string,mixed>> The items, each event once.
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#4-timeline-merge
	 */
	public function withoutDuplicateEvents(array $activities): array {
		$leafUids = [];
		foreach ($activities as $activity) {
			if (($activity['metadata']['source'] ?? null) === 'calendar-leaf') {
				$leafUids[(string)$activity['metadata']['uid']] = true;
			}
		}

		$seen = [];
		$kept = [];
		foreach ($activities as $activity) {
			$uid = (string)($activity['metadata']['uid'] ?? '');
			if ($uid === '' || ($activity['type'] ?? '') !== 'calendar') {
				$kept[] = $activity;
				continue;
			}

			$fromLeaf = (($activity['metadata']['source'] ?? null) === 'calendar-leaf');
			if (isset($seen[$uid]) === true || ($fromLeaf === false && isset($leafUids[$uid]) === true)) {
				continue;
			}

			$seen[$uid] = true;
			$kept[] = $activity;
		}

		return $kept;
	}//end withoutDuplicateEvents()

	/**
	 * The activities of every configured schema the filter includes.
	 *
	 * @param TimelineRun         $run    The entity and the filter.
	 * @param array<string,mixed> $config The resolved register/schema config.
	 *
	 * @return array<int,array<string,mixed>> The normalized activities, in source order.
	 */
	private function schemaActivities(TimelineRun $run, array $config): array {
		$merged = [];
		$perSchemaFilters = $this->resolveEntityQueryParams(entityType: $run->entity->entityType, entityId: $run->entity->entityId);
		foreach ($perSchemaFilters as $sourceType => $sourceFilter) {
			// Skip filtered-out types (only if the user explicitly limited types) and unconfigured schemas.
			if ($this->includesSource(run: $run, sourceType: $sourceType) === false || ($config[$sourceType] ?? '') === '') {
				continue;
			}

			$objects = $this->reader->query(registerId: $config['register'], schemaId: $config[$sourceType], filters: $sourceFilter);
			$source = new TimelineSource(sourceType: $sourceType, objects: $objects);
			$merged = array_merge($merged, $this->normalizeSourceObjects(source: $source, run: $run));
		}

		return $merged;
	}//end schemaActivities()

	/**
	 * The activities of the notes, messages, bookings and linked emails the
	 * filter includes; each source is read only when it is included.
	 *
	 * @param TimelineRun $run        The entity and the filter.
	 * @param string      $registerId The configured register.
	 *
	 * @return array<int,array<string,mixed>> The normalized activities, in source order.
	 */
	private function extraSourceActivities(TimelineRun $run, string $registerId): array {
		$merged = [];
		foreach ($this->extraSources(run: $run, registerId: $registerId) as $sourceType => $read) {
			if ($this->includesSource(run: $run, sourceType: $sourceType) === true) {
				$source = new TimelineSource(sourceType: $sourceType, objects: $read());
				$merged = array_merge($merged, $this->normalizeSourceObjects(source: $source, run: $run));
			}
		}

		return $merged;
	}//end extraSourceActivities()

	/**
	 * The readers of the sources that are not a configured schema.
	 *
	 * @param TimelineRun $run        The entity and the filter.
	 * @param string      $registerId The configured register.
	 *
	 * @return array<string,callable(): array<mixed>> Source type => reader.
	 */
	private function extraSources(TimelineRun $run, string $registerId): array {
		$entityType = $run->entity->entityType;
		$entityId = $run->entity->entityId;
		return [
			'note' => fn (): array => $this->reader->notes(entityId: $entityId),
			'message' => fn (): array => $this->reader->messages(entityType: $entityType, entityId: $entityId, registerId: $registerId),
			'booking' => fn (): array => $this->reader->bookings(entityType: $entityType, entityId: $entityId, registerId: $registerId),
			'linkedEmail' => fn (): array => $this->reader->linkedEmails(entityId: $entityId),
			'leafEvent' => fn (): array => $this->reader->linkedEvents(entityId: $entityId),
		];
	}//end extraSources()

	/**
	 * Whether the filter includes a source: always without a types filter.
	 *
	 * @param TimelineRun $run        The entity and the filter.
	 * @param string      $sourceType The source type.
	 *
	 * @return bool True when the source's activity type is shown.
	 */
	private function includesSource(TimelineRun $run, string $sourceType): bool {
		return $run->filter->types === null
			|| in_array($this->sourceToActivityType(sourceType: $sourceType), $run->filter->types, true) === true;
	}//end includesSource()

	/**
	 * Extract the optional from/to date bounds from request params.
	 *
	 * @param array<string,mixed> $params Request params.
	 *
	 * @return array{0: string|null, 1: string|null} The [from, to] bounds.
	 */
	private function extractDateBounds(array $params): array {
		$from = null;
		if (isset($params['from']) === true && $params['from'] !== '') {
			$from = (string)$params['from'];
		}

		$to = null;
		if (isset($params['to']) === true && $params['to'] !== '') {
			$to = (string)$params['to'];
		}

		return [$from, $to];
	}//end extractDateBounds()

	/**
	 * Normalize one source's objects into activities, applying the date filter.
	 *
	 * @param TimelineSource $source The source type and its raw objects.
	 * @param TimelineRun    $run    The entity and the filter.
	 *
	 * @return array<int,array<string,mixed>> The normalized, in-range activities.
	 */
	private function normalizeSourceObjects(TimelineSource $source, TimelineRun $run): array {
		$activities = [];
		foreach ($source->objects as $object) {
			$normalized = $this->normalizeActivity(
				sourceType: $source->sourceType,
				object: $object,
				entityType: $run->entity->entityType,
				entityId: $run->entity->entityId
			);
			if ($normalized !== null && $this->withinDateRange(date: $normalized['date'], bounds: $run->filter->bounds) === true) {
				$activities[] = $normalized;
			}
		}

		return $activities;
	}//end normalizeSourceObjects()

	/**
	 * Map an internal source-type (matching schema config key) onto the public activity type label.
	 *
	 * @param string $sourceType The source type as used in resolveEntityQueryParams keys.
	 *
	 * @return string The public activity type label.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-2
	 */
	private function sourceToActivityType(string $sourceType): string {
		return match ($sourceType) {
			'interaction' => 'interaction',
			'task' => 'task',
			'emailLink', 'linkedEmail' => 'email',
			'calendarLink', 'booking', 'leafEvent' => 'calendar',
			default => $sourceType,
		};
	}//end sourceToActivityType()

	/**
	 * Normalise the user-supplied types parameter into a list of accepted activity types,
	 * or null if no filter was supplied.
	 *
	 * @param mixed $rawTypes The raw `types` parameter (array, comma-separated string, or null).
	 *
	 * @return array<int,string>|null A list of activity types, or null if no filter applied.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-1
	 */
	private function normaliseTypes(mixed $rawTypes): ?array {
		if ($rawTypes === null || $rawTypes === '' || $rawTypes === []) {
			return null;
		}

		if (is_array($rawTypes) === false) {
			$rawTypes = explode(',', (string)$rawTypes);
		}

		$allowed = ['interaction', 'task', 'email', 'calendar', 'worklog', 'note', 'message'];
		$result = [];
		foreach ($rawTypes as $type) {
			$type = strtolower(trim((string)$type));
			if (in_array($type, $allowed, true) === true) {
				$result[] = $type;
			}
		}

		if ($result === []) {
			return null;
		}

		return array_values(array_unique($result));
	}//end normaliseTypes()

	/**
	 * Apply optional date range filtering to a normalised item.
	 *
	 * @param string|null $date   The item's date in ISO 8601 form (or null).
	 * @param DateBounds  $bounds Optional inclusive start and end dates.
	 *
	 * @return bool True if the date falls inside the requested range.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-4
	 */
	private function withinDateRange(?string $date, DateBounds $bounds): bool {
		if ($bounds->from === null && $bounds->to === null) {
			return true;
		}

		if ($date === null || $date === '') {
			// Items without a date are filtered out when any date range is given.
			return false;
		}

		$itemTs = strtotime($date);
		if ($itemTs === false) {
			return false;
		}

		return $this->isAfterFrom(itemTs: $itemTs, from: $bounds->from)
			&& $this->isBeforeTo(itemTs: $itemTs, to: $bounds->to);
	}//end withinDateRange()

	/**
	 * Whether an item timestamp is at or after the (optional) lower bound.
	 *
	 * @param int $itemTs The item's unix timestamp.
	 * @param string|null $from The lower bound date or null.
	 *
	 * @return bool True when no lower bound applies or the item is in range.
	 */
	private function isAfterFrom(int $itemTs, ?string $from): bool {
		if ($from === null) {
			return true;
		}

		$fromTs = strtotime($from);
		return $fromTs === false || $itemTs >= $fromTs;
	}//end isAfterFrom()

	/**
	 * Whether an item timestamp is at or before the (optional) inclusive upper bound.
	 *
	 * A bare date (length 10) is treated as end-of-day so the upper bound is inclusive.
	 *
	 * @param int $itemTs The item's unix timestamp.
	 * @param string|null $to The upper bound date or null.
	 *
	 * @return bool True when no upper bound applies or the item is in range.
	 */
	private function isBeforeTo(int $itemTs, ?string $to): bool {
		if ($to === null) {
			return true;
		}

		// To-date is inclusive: treat as end-of-day if it's a bare date.
		$toString = $to;
		if (strlen($to) === 10) {
			$toString = $to . 'T23:59:59';
		}

		$toTs = strtotime($toString);
		return $toTs === false || $itemTs <= $toTs;
	}//end isBeforeTo()

	/**
	 * Build per-source filter arrays for an entity-type/id pair.
	 *
	 * The `contactmoment` source reads the unified `ticket` schema, so its filter
	 * always carries the `ticketType` discriminator, and the back-reference to a
	 * request is the ticket field `parentTicket` (formerly contactmoment.request).
	 *
	 * @param string $entityType The entity type (client|request|lead|contact).
	 * @param string $entityId The entity UUID.
	 *
	 * @return array<string,array<string,mixed>> Map of source-type => filter array.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
	 */
	public function resolveEntityQueryParams(string $entityType, string $entityId): array {
		return match ($entityType) {
			'client' => [
				'interaction' => [
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
					'client' => $entityId,
				],
				'task' => ['clientId' => $entityId],
				'emailLink' => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
			],
			'request' => [
				'interaction' => [
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
					'parentTicket' => $entityId,
				],
				'task' => ['requestId' => $entityId],
				'emailLink' => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
			],
			'lead' => [
				'emailLink' => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
			],
			'contact' => [
				'emailLink' => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
			],
			default => [],
		};// End match.
	}//end resolveEntityQueryParams()

	/**
	 * Normalise a raw object array into the unified activity item shape.
	 *
	 * Every item carries `userName`, its author's display name (the user id
	 * when the user is unknown).
	 *
	 * @param string $sourceType The internal source-type key (interaction|task|emailLink|calendarLink|note|message).
	 * @param array<string,mixed> $object The raw object array from OpenRegister.
	 * @param string $entityType The originating entityType for back-reference.
	 * @param string $entityId The originating entityId for back-reference.
	 *
	 * @return array<string,mixed>|null The normalised item, or null if it should be skipped.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-names-authors-by-display-name
	 */
	public function normalizeActivity(string $sourceType, array $object, string $entityType, string $entityId): ?array {
		return $this->itemNormalizer->normalize(sourceType: $sourceType, object: $object, entityType: $entityType, entityId: $entityId);
	}//end normalizeActivity()

	/**
	 * Create a worklog entry as a contactmoment ticket with channel='worklog'.
	 *
	 * Written to the unified `ticket` schema with `ticketType=contactmoment`
	 * (unify-ticket-supertype) using the ticket field names: description
	 * (was summary), occurredAt (was contactedAt), assignee (was agent) and
	 * parentTicket (was request).
	 *
	 * The acting agent is derived from the current IUserSession and MUST NOT be
	 * supplied via request payload.
	 *
	 * @param string $entityType The entity type (client|request).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $data The worklog data (duration, description, date).
	 *
	 * @return array<string,mixed> The normalised created worklog item.
	 *
	 * @throws \RuntimeException If configuration or services are missing.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function createWorklog(string $entityType, string $entityId, array $data): array {
		$config = $this->getConfig();
		if ($config['register'] === '' || $config['interaction'] === '') {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		$payload = $this->worklogPayload(data: $data);

		if ($entityType === 'client') {
			$payload['client'] = $entityId;
		} elseif ($entityType === 'request') {
			$payload['parentTicket'] = $entityId;
		}

		$saved = $this->ticketService->save(TicketService::TYPE_CONTACTMOMENT, $payload);

		$savedArray = $this->extractObjectArray(saved: $saved);
		$normalized = $this->normalizeActivity(
			sourceType: 'interaction',
			object: $savedArray,
			entityType: $entityType,
			entityId: $entityId
		);

		return $normalized ?? [];
	}//end createWorklog()

	/**
	 * The contact moment a worklog becomes, before it is tied to its entity.
	 *
	 * The assignee is the signed-in agent, never a payload value; a missing date is now.
	 *
	 * @param array<string,mixed> $data The worklog data (duration, description, date).
	 *
	 * @return array<string,mixed>
	 */
	private function worklogPayload(array $data): array {
		$user = $this->userSession->getUser();

		$agentUid = '';
		if ($user !== null) {
			$agentUid = (string)$user->getUID();
		}

		$duration = $this->stringOrNull(value: ($data['duration'] ?? null));
		$summary = $this->stringOrNull(value: ($data['description'] ?? null));
		$date = $this->stringOrNull(value: ($data['date'] ?? null)) ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return [
			'channel' => 'worklog',
			// A worklog is a handler writing down what they did, not contact
			// with the party, so it is internal by construction.
			'direction' => 'internal',
			'description' => $summary ?? '',
			'duration' => $duration ?? '',
			'occurredAt' => $date,
			'assignee' => $agentUid,
		];
	}//end worklogPayload()

	/**
	 * Return paginated worklog entries (contactmomenten where channel='worklog')
	 * for an entity, including a summed `totalDuration` field.
	 *
	 * @param string $entityType The entity type (client|request).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $params Request parameters: _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int, totalDuration: string}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function getWorklog(string $entityType, string $entityId, array $params): array {
		return $this->worklog(entity: new TimelineEntity(entityType: $entityType, entityId: $entityId), params: $params);
	}//end getWorklog()

	/**
	 * List the worklog entries for an entity, newest first, paged, with
	 * the total duration of all its entries.
	 *
	 * @param TimelineEntity      $entity The entity (client|request).
	 * @param array<string,mixed> $params Request params: _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int, totalDuration: string}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function worklog(TimelineEntity $entity, array $params): array {
		$pageRequest = $this->pageRequest(params: $params);

		$config = $this->getConfig();
		if ($config['register'] === '' || $config['interaction'] === '') {
			return ['items' => [], 'total' => 0, 'page' => $pageRequest->page, 'pages' => 1, 'totalDuration' => 'PT0S'];
		}

		$filters = self::worklogFilters(entity: $entity);
		$rawObjects = $this->reader->query(registerId: $config['register'], schemaId: $config['interaction'], filters: $filters);
		[$items, $totalSeconds] = $this->collectWorklogItems(rawObjects: $rawObjects, entity: $entity);

		usort(
			$items,
			static function (array $left, array $right): int {
				return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
			}
		);

		$worklogPage = (new TimelinePages())->worklogPage(items: $items, pageRequest: $pageRequest);
		return $worklogPage + ['totalDuration' => $this->secondsToIsoDuration(seconds: $totalSeconds)];
	}//end worklog()

	/**
	 * The ticket filters of an entity's worklog.
	 *
	 * Worklogs are contactmoment tickets: narrow the unified `ticket` schema
	 * with the discriminator, and back-reference a request through the ticket
	 * field `parentTicket` (formerly contactmoment.request).
	 *
	 * @param TimelineEntity $entity The entity.
	 *
	 * @return array<string,string> The filters.
	 */
	private static function worklogFilters(TimelineEntity $entity): array {
		$filters = [
			'ticketType' => TicketService::TYPE_CONTACTMOMENT,
			'channel' => 'worklog',
		];
		if ($entity->entityType === 'client') {
			$filters['client'] = $entity->entityId;
		} elseif ($entity->entityType === 'request') {
			$filters['parentTicket'] = $entity->entityId;
		}

		return $filters;
	}//end worklogFilters()

	/**
	 * Normalise worklog contactmoments and accumulate their total duration.
	 *
	 * @param iterable<mixed> $rawObjects The raw worklog objects from the query.
	 * @param TimelineEntity  $entity     The entity.
	 *
	 * @return array{0: array<int,array<string,mixed>>, 1: int} Tuple of (items, totalSeconds).
	 */
	private function collectWorklogItems(iterable $rawObjects, TimelineEntity $entity): array {
		$items = [];
		$totalSeconds = 0;
		foreach ($rawObjects as $object) {
			$normalised = $this->normalizeActivity(
				sourceType: 'interaction',
				object: $object,
				entityType: $entity->entityType,
				entityId: $entity->entityId
			);
			if ($normalised === null) {
				continue;
			}

			$items[] = $normalised;
			$totalSeconds += $this->isoDurationToSeconds(duration: (string)($normalised['metadata']['duration'] ?? ''));
		}

		return [$items, $totalSeconds];
	}//end collectWorklogItems()

	/**
	 * Extract a plain array from an OpenRegister saveObject return value.
	 *
	 * @param mixed $saved The raw save result.
	 *
	 * @return array<string,mixed> The flat object array.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-3
	 */
	private function extractObjectArray(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			$value = $saved->getObject();
			if (is_array($value) === true) {
				if (isset($value['id']) === false && method_exists($saved, 'getUuid') === true) {
					$value['id'] = $saved->getUuid();
				}

				return $value;
			}
		}

		if (is_object($saved) === true) {
			return (array)$saved;
		}

		return [];
	}//end extractObjectArray()

	/**
	 * Parse an ISO 8601 duration string to seconds.
	 *
	 * Supports the common subset PT[H][M][S] and P[D]T[H][M][S]. Anything
	 * unparseable yields zero.
	 *
	 * @param string $duration The ISO 8601 duration (e.g. PT2H30M).
	 *
	 * @return int Total seconds.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-5
	 */
	private function isoDurationToSeconds(string $duration): int {
		if ($duration === '') {
			return 0;
		}

		try {
			$interval = new DateInterval($duration);
		} catch (Throwable $e) {
			return 0;
		}

		$seconds = (((($interval->y * 365) + ($interval->m * 30) + $interval->d) * 24) + $interval->h) * 3600;
		$seconds += ($interval->i * 60);
		$seconds += $interval->s;
		return $seconds;
	}//end isoDurationToSeconds()

	/**
	 * Format a total number of seconds as a compact ISO 8601 duration.
	 *
	 * @param int $seconds The number of seconds.
	 *
	 * @return string The ISO 8601 duration.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-5
	 */
	private function secondsToIsoDuration(int $seconds): string {
		if ($seconds <= 0) {
			return 'PT0S';
		}

		$hours = intdiv($seconds, 3600);
		$minutes = intdiv(($seconds % 3600), 60);
		$secs = ($seconds % 60);

		$output = 'PT';
		if ($hours > 0) {
			$output .= $hours . 'H';
		}

		if ($minutes > 0) {
			$output .= $minutes . 'M';
		}

		if ($secs > 0 || ($hours === 0 && $minutes === 0)) {
			$output .= $secs . 'S';
		}

		return $output;
	}//end secondsToIsoDuration()

	/**
	 * Coerce a value to a string, or return null when empty/null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string value or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$stringValue = '';
		if (is_scalar($value) === true) {
			$stringValue = (string)$value;
		}

		if ($stringValue === '') {
			return null;
		}

		return $stringValue;
	}//end stringOrNull()

	/**
	 * Build an empty timeline result.
	 *
	 * @param int $page The requested page (echoed back).
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 */
	private function emptyResult(int $page): array {
		return [
			'items' => [],
			'total' => 0,
			'page' => $page,
			'pages' => 1,
		];
	}//end emptyResult()
}//end class
