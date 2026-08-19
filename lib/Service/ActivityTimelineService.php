<?php

/**
 * Pipelinq ActivityTimelineService.
 *
 * Service that aggregates CRM activity instances (contactmomenten, tasks,
 * emailLinks, calendarLinks) for an entity into a unified timeline, and
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
 * @spec openspec/changes/activity-timeline/tasks.md#task-1
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Aggregates CRM activity data from multiple OpenRegister schemas.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/activity-timeline/tasks.md#task-1
 */
class ActivityTimelineService
{
    /**
     * Maximum allowed page size for list endpoints.
     */
    private const MAX_LIMIT = 100;

    /**
     * Default page size for list endpoints.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * Per-schema fetch ceiling when aggregating timelines. The service queries
     * each source schema, merges results, sorts by date desc and applies
     * pagination in PHP, so we must cap the per-schema window.
     */
    private const PER_SCHEMA_CEILING = 500;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container     The DI container (used to lazily fetch ObjectService).
     * @param IAppConfig         $appConfig     The app config service.
     * @param IUserSession       $userSession   The current user session.
     * @param LoggerInterface    $logger        The logger.
     * @param TicketService      $ticketService Resolver for the unified `ticket` supertype —
     *                                          contactmomenten (incl. worklogs) are tickets with
     *                                          `ticketType=contactmoment`, not their own schema.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private TicketService $ticketService,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The OpenRegister ObjectService instance.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

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
    private function getConfig(): array
    {
        $register     = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $task         = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        $emailLink    = $this->appConfig->getValueString(Application::APP_ID, 'emailLink_schema', '');
        $calendarLink = $this->appConfig->getValueString(Application::APP_ID, 'calendarLink_schema', '');
        if ($register === '') {
            $this->logger->warning('Pipelinq: "register" is not configured; the activity timeline is refused, not run unscoped');
        }

        return [
            'register'      => $register,
            'contactmoment' => $this->ticketService->getSchemaId(),
            'task'          => $task,
            'emailLink'     => $emailLink,
            'calendarLink'  => $calendarLink,
        ];
    }//end getConfig()

    /**
     * Build the merged, sorted, paginated activity timeline for an entity.
     *
     * @param string              $entityType The entity type (client|request|lead|contact).
     * @param string              $entityId   The entity UUID.
     * @param array<string,mixed> $params     Request parameters: from, to, types[], _page, _limit.
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
     */
    public function getTimeline(string $entityType, string $entityId, array $params): array
    {
        $page  = max(1, (int) ($params['_page'] ?? 1));
        $limit = $this->normaliseLimit(rawLimit: ($params['_limit'] ?? null));

        $config     = $this->getConfig();
        $registerId = $config['register'];

        // If no register is configured, return an empty timeline (do not raise).
        if ($registerId === '') {
            return $this->emptyResult(page: $page);
        }

        $merged = $this->collectActivities(
            entityType: $entityType,
            entityId: $entityId,
            params: $params,
            config: $config
        );

        // Sort by date descending (newest first).
        usort(
            $merged,
            static function (array $left, array $right): int {
                $leftDate  = (string) ($left['date'] ?? '');
                $rightDate = (string) ($right['date'] ?? '');
                return strcmp($rightDate, $leftDate);
            }
        );

        return $this->paginate(merged: $merged, page: $page, limit: $limit);
    }//end getTimeline()

    /**
     * Clamp a raw limit param into the [1, MAX_LIMIT] range.
     *
     * @param mixed $rawLimit The raw `_limit` request param.
     *
     * @return int The clamped page size.
     */
    private function normaliseLimit(mixed $rawLimit): int
    {
        $limit = (int) ($rawLimit ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        return $limit;
    }//end normaliseLimit()

    /**
     * Query every configured schema and merge the normalized activities for an entity.
     *
     * @param string              $entityType The entity type.
     * @param string              $entityId   The entity UUID.
     * @param array<string,mixed> $params     Request params (types[], from, to).
     * @param array<string,mixed> $config     The resolved register/schema config.
     *
     * @return array<int,array<string,mixed>> The merged, unsorted activities.
     */
    private function collectActivities(string $entityType, string $entityId, array $params, array $config): array
    {
        $typesFilter      = $this->normaliseTypes(rawTypes: ($params['types'] ?? null));
        [$from, $to]      = $this->extractDateBounds(params: $params);
        $perSchemaFilters = $this->resolveEntityQueryParams(entityType: $entityType, entityId: $entityId);
        $registerId       = $config['register'];

        $merged = [];

        foreach ($perSchemaFilters as $sourceType => $sourceFilter) {
            // Skip filtered-out types (only if the user explicitly limited types).
            if ($typesFilter !== null && in_array($this->sourceToActivityType(sourceType: $sourceType), $typesFilter, true) === false) {
                continue;
            }

            $schemaId = $config[$sourceType] ?? '';
            if ($schemaId === '') {
                continue;
            }

            $objects = $this->querySchema(
                registerId: $registerId,
                schemaId: $schemaId,
                filters: $sourceFilter
            );

            $merged = array_merge(
                $merged,
                $this->normalizeSchemaObjects(
                    objects: $objects,
                    sourceType: $sourceType,
                    entityType: $entityType,
                    entityId: $entityId,
                    from: $from,
                    to: $to
                )
            );
        }//end foreach

        return $merged;
    }//end collectActivities()

    /**
     * Extract the optional from/to date bounds from request params.
     *
     * @param array<string,mixed> $params Request params.
     *
     * @return array{0: string|null, 1: string|null} The [from, to] bounds.
     */
    private function extractDateBounds(array $params): array
    {
        $from = null;
        if (isset($params['from']) === true && $params['from'] !== '') {
            $from = (string) $params['from'];
        }

        $to = null;
        if (isset($params['to']) === true && $params['to'] !== '') {
            $to = (string) $params['to'];
        }

        return [$from, $to];
    }//end extractDateBounds()

    /**
     * Normalize a single schema's objects into activities, applying the date filter.
     *
     * @param iterable<mixed> $objects    The raw objects from the schema query.
     * @param string          $sourceType The source type / schema config key.
     * @param string          $entityType The entity type.
     * @param string          $entityId   The entity UUID.
     * @param string|null     $from       The lower date bound (inclusive) or null.
     * @param string|null     $to         The upper date bound (inclusive) or null.
     *
     * @return array<int,array<string,mixed>> The normalized, in-range activities.
     */
    private function normalizeSchemaObjects(
        iterable $objects,
        string $sourceType,
        string $entityType,
        string $entityId,
        ?string $from,
        ?string $to
    ): array {
        $activities = [];
        foreach ($objects as $object) {
            $normalized = $this->normalizeActivity(
                sourceType: $sourceType,
                object: $object,
                entityType: $entityType,
                entityId: $entityId
            );
            if ($normalized === null) {
                continue;
            }

            if ($this->withinDateRange(date: $normalized['date'], from: $from, to: $to) === false) {
                continue;
            }

            $activities[] = $normalized;
        }

        return $activities;
    }//end normalizeSchemaObjects()

    /**
     * Slice a sorted activity list into the requested page envelope.
     *
     * @param array<int,array<string,mixed>> $merged The sorted activities.
     * @param int                            $page   The 1-based requested page.
     * @param int                            $limit  The page size.
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
     */
    private function paginate(array $merged, int $page, int $limit): array
    {
        $total = count($merged);

        $pages = 1;
        if ($total !== 0) {
            $pages = (int) ceil($total / $limit);
        }

        $start = (($page - 1) * $limit);
        $page  = min($page, $pages);

        if ($start < 0) {
            $start = 0;
        }

        $slice = array_slice($merged, $start, $limit);

        return [
            'items' => array_values($slice),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }//end paginate()

    /**
     * Map an internal source-type (matching schema config key) onto the public activity type label.
     *
     * @param string $sourceType The source type as used in resolveEntityQueryParams keys.
     *
     * @return string The public activity type label.
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-2
     */
    private function sourceToActivityType(string $sourceType): string
    {
        return match ($sourceType) {
            'contactmoment' => 'contactmoment',
            'task'          => 'task',
            'emailLink'     => 'email',
            'calendarLink'  => 'calendar',
            default         => $sourceType,
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
    private function normaliseTypes(mixed $rawTypes): ?array
    {
        if ($rawTypes === null || $rawTypes === '' || $rawTypes === []) {
            return null;
        }

        if (is_array($rawTypes) === false) {
            $rawTypes = explode(',', (string) $rawTypes);
        }

        $allowed = ['contactmoment', 'task', 'email', 'calendar', 'worklog'];
        $result  = [];
        foreach ($rawTypes as $type) {
            $type = strtolower(trim((string) $type));
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
     * Query a single OpenRegister schema using a set of equality filters.
     *
     * @param string              $registerId The register ID.
     * @param string              $schemaId   The schema ID.
     * @param array<string,mixed> $filters    Field equality filters.
     *
     * @return array<int,array<string,mixed>> The raw object arrays.
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-3
     */
    private function querySchema(string $registerId, string $schemaId, array $filters): array
    {
        try {
            $objectService = $this->getObjectService();

            $params = [
                'filters' => array_merge(
                    [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    $filters
                ),
                'limit'   => self::PER_SCHEMA_CEILING,
            ];

            $results = $objectService->findAll($params);

            return $this->normaliseResultset(results: $results);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActivityTimelineService: failed to query schema',
                [
                    'exception' => $e->getMessage(),
                    'schemaId'  => $schemaId,
                ]
            );
            return [];
        }//end try
    }//end querySchema()

    /**
     * Normalise an OpenRegister findAll result into an array of plain arrays.
     *
     * @param mixed $results The raw ObjectService->findAll return value.
     *
     * @return array<int,array<string,mixed>> A list of plain object arrays.
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-3
     */
    private function normaliseResultset(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        $output = [];
        foreach ($results as $item) {
            $normalised = $this->normaliseResultItem(item: $item);
            if ($normalised !== null) {
                $output[] = $normalised;
            }
        }//end foreach

        return $output;
    }//end normaliseResultset()

    /**
     * Normalise a single result-set entry into an array payload.
     *
     * Arrays pass through unchanged; OpenRegister entities are unwrapped via
     * getObject() (promoting id/uuid when missing); other objects are cast to
     * array. Scalars yield null.
     *
     * @param mixed $item The raw result entry.
     *
     * @return array<string,mixed>|null The normalised payload, or null to skip.
     */
    private function normaliseResultItem(mixed $item): ?array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === false) {
            return null;
        }

        if (method_exists($item, 'getObject') === true) {
            $value = $item->getObject();
            if (is_array($value) === true) {
                // Promote id/uuid into the object payload if missing.
                if (isset($value['id']) === false && method_exists($item, 'getUuid') === true) {
                    $value['id'] = $item->getUuid();
                }

                return $value;
            }
        }

        return (array) $item;
    }//end normaliseResultItem()

    /**
     * Apply optional date range filtering to a normalised item.
     *
     * @param string|null $date The item's date in ISO 8601 form (or null).
     * @param string|null $from Optional inclusive start date.
     * @param string|null $to   Optional inclusive end date.
     *
     * @return bool True if the date falls inside the requested range.
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-4
     */
    private function withinDateRange(?string $date, ?string $from, ?string $to): bool
    {
        if ($from === null && $to === null) {
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

        return $this->isAfterFrom(itemTs: $itemTs, from: $from)
            && $this->isBeforeTo(itemTs: $itemTs, to: $to);
    }//end withinDateRange()

    /**
     * Whether an item timestamp is at or after the (optional) lower bound.
     *
     * @param int         $itemTs The item's unix timestamp.
     * @param string|null $from   The lower bound date or null.
     *
     * @return bool True when no lower bound applies or the item is in range.
     */
    private function isAfterFrom(int $itemTs, ?string $from): bool
    {
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
     * @param int         $itemTs The item's unix timestamp.
     * @param string|null $to     The upper bound date or null.
     *
     * @return bool True when no upper bound applies or the item is in range.
     */
    private function isBeforeTo(int $itemTs, ?string $to): bool
    {
        if ($to === null) {
            return true;
        }

        // To-date is inclusive: treat as end-of-day if it's a bare date.
        $toString = $to;
        if (strlen($to) === 10) {
            $toString = $to.'T23:59:59';
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
     * @param string $entityId   The entity UUID.
     *
     * @return array<string,array<string,mixed>> Map of source-type => filter array.
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
     */
    public function resolveEntityQueryParams(string $entityType, string $entityId): array
    {
        return match ($entityType) {
            'client' => [
                'contactmoment' => [
                    'ticketType' => TicketService::TYPE_CONTACTMOMENT,
                    'client'     => $entityId,
                ],
                'task'          => ['clientId' => $entityId],
                'emailLink'     => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
                'calendarLink'  => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
            ],
            'request' => [
                'contactmoment' => [
                    'ticketType'   => TicketService::TYPE_CONTACTMOMENT,
                    'parentTicket' => $entityId,
                ],
                'task'          => ['requestId' => $entityId],
                'emailLink'     => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
                'calendarLink'  => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
            ],
            'lead' => [
                'emailLink'    => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
                'calendarLink' => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
            ],
            'contact' => [
                'emailLink'    => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
                'calendarLink' => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
            ],
            default => [],
        };//end match
    }//end resolveEntityQueryParams()

    /**
     * Normalise a raw object array into the unified activity item shape.
     *
     * @param string              $sourceType The internal source-type key (contactmoment|task|emailLink|calendarLink).
     * @param array<string,mixed> $object     The raw object array from OpenRegister.
     * @param string              $entityType The originating entityType for back-reference.
     * @param string              $entityId   The originating entityId for back-reference.
     *
     * @return array<string,mixed>|null The normalised item, or null if it should be skipped.
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
     */
    public function normalizeActivity(string $sourceType, array $object, string $entityType, string $entityId): ?array
    {
        $id = (string) ($object['id'] ?? $object['uuid'] ?? '');

        switch ($sourceType) {
            case 'contactmoment':
                // Ticket field names (unify-ticket-supertype): subject -> title,
                // summary -> description, contactedAt -> occurredAt, agent -> assignee.
                $channel = (string) ($object['channel'] ?? '');
                $type    = 'contactmoment';
                if ($channel === 'worklog') {
                    $type = 'worklog';
                }
                return [
                    'type'        => $type,
                    'id'          => $id,
                    'title'       => (string) ($object['title'] ?? $object['description'] ?? ''),
                    'description' => (string) ($object['description'] ?? ''),
                    'date'        => $this->stringOrNull(value: ($object['occurredAt'] ?? null)),
                    'user'        => $this->stringOrNull(value: ($object['assignee'] ?? null)),
                    'entityType'  => $entityType,
                    'entityId'    => $entityId,
                    'metadata'    => [
                        'channel'  => $channel,
                        'duration' => $this->stringOrNull(value: ($object['duration'] ?? null)),
                        'outcome'  => $this->stringOrNull(value: ($object['outcome'] ?? null)),
                    ],
                ];

            case 'task':
                return [
                    'type'        => 'task',
                    'id'          => $id,
                    'title'       => (string) ($object['subject'] ?? $object['title'] ?? ''),
                    'description' => (string) ($object['description'] ?? ''),
                    'date'        => $this->stringOrNull(value: ($object['deadline'] ?? $object['createdAt'] ?? null)),
                    'user'        => $this->stringOrNull(value: ($object['assigneeUserId'] ?? null)),
                    'entityType'  => $entityType,
                    'entityId'    => $entityId,
                    'metadata'    => [
                        'status'   => $this->stringOrNull(value: ($object['status'] ?? null)),
                        'priority' => $this->stringOrNull(value: ($object['priority'] ?? null)),
                    ],
                ];

            case 'emailLink':
                return [
                    'type'        => 'email',
                    'id'          => $id,
                    'title'       => (string) ($object['subject'] ?? ''),
                    'description' => (string) ($object['sender'] ?? ''),
                    'date'        => $this->stringOrNull(value: ($object['date'] ?? null)),
                    'user'        => null,
                    'entityType'  => $entityType,
                    'entityId'    => $entityId,
                    'metadata'    => [
                        'direction' => $this->stringOrNull(value: ($object['direction'] ?? null)),
                        'messageId' => $this->stringOrNull(value: ($object['messageId'] ?? null)),
                    ],
                ];

            case 'calendarLink':
                return [
                    'type'        => 'calendar',
                    'id'          => $id,
                    'title'       => (string) ($object['title'] ?? $object['subject'] ?? ''),
                    'description' => (string) ($object['notes'] ?? $object['description'] ?? ''),
                    'date'        => $this->stringOrNull(value: ($object['startDate'] ?? null)),
                    'user'        => null,
                    'entityType'  => $entityType,
                    'entityId'    => $entityId,
                    'metadata'    => [
                        'endDate'  => $this->stringOrNull(value: ($object['endDate'] ?? null)),
                        'location' => $this->stringOrNull(value: ($object['location'] ?? null)),
                    ],
                ];

            default:
                return null;
        }//end switch
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
     * @param string              $entityType The entity type (client|request).
     * @param string              $entityId   The entity UUID.
     * @param array<string,mixed> $data       The worklog data (duration, description, date).
     *
     * @return array<string,mixed> The normalised created worklog item.
     *
     * @throws \RuntimeException If configuration or services are missing.
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
     */
    public function createWorklog(string $entityType, string $entityId, array $data): array
    {
        $config = $this->getConfig();
        if ($config['register'] === '' || $config['contactmoment'] === '') {
            throw new RuntimeException('Contactmoment register or schema not configured.');
        }

        $user = $this->userSession->getUser();

        $agentUid = '';
        if ($user !== null) {
            $agentUid = (string) $user->getUID();
        }

        $duration = $this->stringOrNull(value: ($data['duration'] ?? null));
        $summary  = $this->stringOrNull(value: ($data['description'] ?? null));
        $date     = $this->stringOrNull(value: ($data['date'] ?? null)) ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $payload = [
            'channel'     => 'worklog',
            'description' => $summary ?? '',
            'duration'    => $duration ?? '',
            'occurredAt'  => $date,
            'assignee'    => $agentUid,
        ];

        if ($entityType === 'client') {
            $payload['client'] = $entityId;
        } else if ($entityType === 'request') {
            $payload['parentTicket'] = $entityId;
        }

        $saved = $this->ticketService->save(TicketService::TYPE_CONTACTMOMENT, $payload);

        $savedArray = $this->extractObjectArray(saved: $saved);
        $normalized = $this->normalizeActivity(
            sourceType: 'contactmoment',
            object: $savedArray,
            entityType: $entityType,
            entityId: $entityId
        );

        return $normalized ?? [];
    }//end createWorklog()

    /**
     * Return paginated worklog entries (contactmomenten where channel='worklog')
     * for an entity, including a summed `totalDuration` field.
     *
     * @param string              $entityType The entity type (client|request).
     * @param string              $entityId   The entity UUID.
     * @param array<string,mixed> $params     Request parameters: _page, _limit.
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int, totalDuration: string}
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
     */
    public function getWorklog(string $entityType, string $entityId, array $params): array
    {
        $page  = max(1, (int) ($params['_page'] ?? 1));
        $limit = $this->normaliseLimit(rawLimit: ($params['_limit'] ?? null));

        $config = $this->getConfig();
        if ($config['register'] === '' || $config['contactmoment'] === '') {
            return [
                'items'         => [],
                'total'         => 0,
                'page'          => $page,
                'pages'         => 1,
                'totalDuration' => 'PT0S',
            ];
        }

        // Worklogs are contactmoment tickets: narrow the unified `ticket` schema
        // with the discriminator, and back-reference a request through the ticket
        // field `parentTicket` (formerly contactmoment.request).
        $filters = [
            'ticketType' => TicketService::TYPE_CONTACTMOMENT,
            'channel'    => 'worklog',
        ];
        if ($entityType === 'client') {
            $filters['client'] = $entityId;
        } else if ($entityType === 'request') {
            $filters['parentTicket'] = $entityId;
        }

        $rawObjects = $this->querySchema(
            registerId: $config['register'],
            schemaId: $config['contactmoment'],
            filters: $filters
        );

        [$items, $totalSeconds] = $this->collectWorklogItems(
            rawObjects: $rawObjects,
            entityType: $entityType,
            entityId: $entityId
        );

        usort(
            $items,
            static function (array $left, array $right): int {
                return strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? ''));
            }
        );

        $total = count($items);

        $pages = 1;
        if ($total !== 0) {
            $pages = (int) ceil($total / $limit);
        }

        $page  = min($page, $pages);
        $start = (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        return [
            'items'         => array_values(array_slice($items, $start, $limit)),
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'totalDuration' => $this->secondsToIsoDuration(seconds: $totalSeconds),
        ];
    }//end getWorklog()

    /**
     * Normalise worklog contactmoments and accumulate their total duration.
     *
     * @param iterable<mixed> $rawObjects The raw worklog objects from the query.
     * @param string          $entityType The entity type.
     * @param string          $entityId   The entity UUID.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: int} Tuple of (items, totalSeconds).
     */
    private function collectWorklogItems(iterable $rawObjects, string $entityType, string $entityId): array
    {
        $items        = [];
        $totalSeconds = 0;
        foreach ($rawObjects as $object) {
            $normalised = $this->normalizeActivity(
                sourceType: 'contactmoment',
                object: $object,
                entityType: $entityType,
                entityId: $entityId
            );
            if ($normalised === null) {
                continue;
            }

            $items[]       = $normalised;
            $duration      = (string) ($normalised['metadata']['duration'] ?? '');
            $totalSeconds += $this->isoDurationToSeconds(duration: $duration);
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
    private function extractObjectArray(mixed $saved): array
    {
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
            return (array) $saved;
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
    private function isoDurationToSeconds(string $duration): int
    {
        if ($duration === '') {
            return 0;
        }

        try {
            $interval = new DateInterval($duration);
        } catch (Throwable $e) {
            return 0;
        }

        $seconds  = (((($interval->y * 365) + ($interval->m * 30) + $interval->d) * 24) + $interval->h) * 3600;
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
    private function secondsToIsoDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'PT0S';
        }

        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv(($seconds % 3600), 60);
        $secs    = ($seconds % 60);

        $output = 'PT';
        if ($hours > 0) {
            $output .= $hours.'H';
        }

        if ($minutes > 0) {
            $output .= $minutes.'M';
        }

        if ($secs > 0 || ($hours === 0 && $minutes === 0)) {
            $output .= $secs.'S';
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
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = '';
        if (is_scalar($value) === true) {
            $stringValue = (string) $value;
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
    private function emptyResult(int $page): array
    {
        return [
            'items' => [],
            'total' => 0,
            'page'  => $page,
            'pages' => 1,
        ];
    }//end emptyResult()
}//end class
