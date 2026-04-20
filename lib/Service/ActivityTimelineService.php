<?php

/**
 * Pipelinq ActivityTimelineService.
 *
 * Service for querying and aggregating CRM activity timelines.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for activity timeline queries and worklog management.
 *
 * Aggregates activity from multiple schemas (contactmoment, task, emailLink, calendarLink)
 * and provides normalized timeline and worklog APIs.
 *
 * @spec openspec/changes/activity-timeline/tasks.md#task-1
 */
class ActivityTimelineService
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig   The app config.
     * @param IUserSession       $userSession The user session.
     * @param ContainerInterface $container   The container.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the unified activity timeline for an entity.
     *
     * @param string $entityType The entity type (client, request, lead, contact).
     * @param string $entityId   The entity UUID.
     * @param array  $params     Query parameters (from, to, types[], _page, _limit).
     *
     * @return array{items: array, total: int, page: int, pages: int} The paginated timeline.
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-1
     */
    public function getTimeline(string $entityType, string $entityId, array $params): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

        $items        = [];
        $queryParams  = $this->resolveEntityQueryParams(paramName: $entityType, entityId: $entityId);
        $allowedTypes = $params['types'] ?? [];
        $fromDate     = $params['from'] ?? null;
        $toDate       = $params['to'] ?? null;

        // Query each applicable schema.
        foreach ($queryParams as $schemaType => $filterParams) {
            $schemaConfigKey = $this->getSchemaConfigKey(paramName: $schemaType);
            $schemaId        = $this->appConfig->getValueString(Application::APP_ID, $schemaConfigKey, '');

            if ($schemaId === '' || $register === '') {
                continue;
            }

            try {
                // Build params with filters.
                $findParams = array_merge(
                    $filterParams,
                    [
                        '_limit'  => 999,
                        '_offset' => 0,
                    ]
                );

                $results = $objectService->findObjects(
                    register: $register,
                    schema: $schemaId,
                    params: $findParams
                );

                if (is_array($results) === true) {
                    foreach ($results as $result) {
                        $normalizedItem = $this->normalizeActivity(type: $schemaType, object: (array) $result);
                        if ($normalizedItem === null) {
                            continue;
                        }

                        // Filter by date range.
                        if ($fromDate !== null && $normalizedItem['date'] < $fromDate) {
                            continue;
                        }

                        if ($toDate !== null && $normalizedItem['date'] > $toDate) {
                            continue;
                        }

                        // Filter by activity types.
                        $hasTypesFilter = empty($allowedTypes) === false;
                        $isAllowedType  = in_array($normalizedItem['type'], $allowedTypes, true);
                        if ($hasTypesFilter === true && $isAllowedType === false) {
                            continue;
                        }

                        $items[] = $normalizedItem;
                    }//end foreach
                }//end if
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to query timeline for schema type',
                    [
                        'schemaType' => $schemaType,
                        'entityType' => $entityType,
                        'exception'  => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        // Sort by date descending (newest first).
        usort(
            $items,
            function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            }
        );

        // Pagination.
        $page   = (int) ($params['_page'] ?? 1);
        $limit  = (int) ($params['_limit'] ?? 20);
        $limit  = min($limit, 100);
        $total  = count($items);
        $pages  = (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;

        $paginatedItems = array_slice($items, $offset, $limit);

        return [
            'items' => $paginatedItems,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }//end getTimeline()

    /**
     * Normalize an activity item from any source schema.
     *
     * @param string $type   The activity type (contactmoment, task, email, calendar, worklog).
     * @param array  $object The object from OpenRegister.
     *
     * @return ?array The normalized activity item or null if cannot normalize.
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-1
     */
    public function normalizeActivity(string $type, array $object): ?array
    {
        return match ($type) {
            'contactmoment'  => $this->normalizeContactmoment(object: $object),
            'task'           => $this->normalizeTask(object: $object),
            'emailLink'      => $this->normalizeEmailLink(object: $object),
            'calendarLink'   => $this->normalizeCalendarLink(object: $object),
            default          => null,
        };
    }//end normalizeActivity()

    /**
     * Normalize a contactmoment object.
     *
     * @param array $object The contactmoment object.
     *
     * @return array The normalized activity item.
     */
    private function normalizeContactmoment(array $object): array
    {
        $isWorklog = ($object['channel'] ?? '') === 'worklog';
        $type      = 'contactmoment';
        if ($isWorklog === true) {
            $type = 'worklog';
        }

        $entityType = '';
        if (isset($object['client']) === true) {
            $entityType = 'client';
        } else if (isset($object['request']) === true) {
            $entityType = 'request';
        }

        return [
            'type'        => $type,
            'id'          => $object['id'] ?? $object['uuid'] ?? '',
            'title'       => $object['subject'] ?? '',
            'description' => $object['summary'] ?? '',
            'date'        => $object['contactedAt'] ?? '',
            'user'        => $object['agent'] ?? '',
            'entityType'  => $entityType,
            'entityId'    => $object['client'] ?? $object['request'] ?? '',
            'metadata'    => [
                'channel'  => $object['channel'] ?? '',
                'duration' => $object['duration'] ?? '',
                'outcome'  => $object['outcome'] ?? '',
            ],
        ];
    }//end normalizeContactmoment()

    /**
     * Normalize a task object.
     *
     * @param array $object The task object.
     *
     * @return array The normalized activity item.
     */
    private function normalizeTask(array $object): array
    {
        $entityType = '';
        if (isset($object['clientId']) === true) {
            $entityType = 'client';
        } else if (isset($object['requestId']) === true) {
            $entityType = 'request';
        }

        return [
            'type'        => 'task',
            'id'          => $object['id'] ?? $object['uuid'] ?? '',
            'title'       => $object['subject'] ?? '',
            'description' => $object['description'] ?? '',
            'date'        => $object['deadline'] ?? $object['createdAt'] ?? '',
            'user'        => $object['assigneeUserId'] ?? '',
            'entityType'  => $entityType,
            'entityId'    => $object['clientId'] ?? $object['requestId'] ?? '',
            'metadata'    => [
                'status'   => $object['status'] ?? '',
                'priority' => $object['priority'] ?? '',
            ],
        ];
    }//end normalizeTask()

    /**
     * Normalize an emailLink object.
     *
     * @param array $object The emailLink object.
     *
     * @return array The normalized activity item.
     */
    private function normalizeEmailLink(array $object): array
    {
        return [
            'type'        => 'email',
            'id'          => $object['id'] ?? $object['uuid'] ?? '',
            'title'       => $object['subject'] ?? '',
            'description' => $object['sender'] ?? '',
            'date'        => $object['date'] ?? '',
            'user'        => null,
            'entityType'  => $object['linkedEntityType'] ?? '',
            'entityId'    => $object['linkedEntityId'] ?? '',
            'metadata'    => [
                'messageId' => $object['messageId'] ?? '',
                'threadId'  => $object['threadId'] ?? '',
            ],
        ];
    }//end normalizeEmailLink()

    /**
     * Normalize a calendarLink object.
     *
     * @param array $object The calendarLink object.
     *
     * @return array The normalized activity item.
     */
    private function normalizeCalendarLink(array $object): array
    {
        return [
            'type'        => 'calendar',
            'id'          => $object['id'] ?? $object['uuid'] ?? '',
            'title'       => $object['title'] ?? '',
            'description' => $object['notes'] ?? '',
            'date'        => $object['startDate'] ?? '',
            'user'        => null,
            'entityType'  => $object['linkedEntityType'] ?? '',
            'entityId'    => $object['linkedEntityId'] ?? '',
            'metadata'    => [
                'eventUid'  => $object['eventUid'] ?? '',
                'endDate'   => $object['endDate'] ?? '',
                'attendees' => $object['attendees'] ?? [],
            ],
        ];
    }//end normalizeCalendarLink()

    /**
     * Resolve query parameters per schema type for an entity.
     *
     * @param string $paramName The entity type (client, request, lead, contact).
     * @param string $entityId  The entity UUID.
     *
     * @return array{contactmoment?: array, task?: array, emailLink?: array, calendarLink?: array} Per-schema filters.
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-1
     */
    public function resolveEntityQueryParams(string $paramName, string $entityId): array
    {
        $params = [];

        // Map entityType to query filters per schema.
        switch ($paramName) {
            case 'client':
                $params['contactmoment'] = ['client' => $entityId];
                $params['task']          = ['clientId' => $entityId];
                $params['emailLink']     = ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId];
                $params['calendarLink']  = ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId];
                break;

            case 'request':
                $params['contactmoment'] = ['request' => $entityId];
                $params['task']          = ['requestId' => $entityId];
                $params['emailLink']     = ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId];
                $params['calendarLink']  = ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId];
                break;

            case 'lead':
                $params['emailLink']    = ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId];
                $params['calendarLink'] = ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId];
                break;

            case 'contact':
                $params['emailLink']    = ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId];
                $params['calendarLink'] = ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId];
                break;
        }//end switch

        return $params;
    }//end resolveEntityQueryParams()

    /**
     * Create a worklog entry for an entity.
     *
     * @param string $entityType The entity type (client, request, lead, contact).
     * @param string $entityId   The entity UUID.
     * @param array  $data       The worklog data (duration, description, date).
     *
     * @return array The created contactmoment object.
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-1
     */
    public function createWorklog(string $entityType, string $entityId, array $data): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId      = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');

        if ($register === '' || $schemaId === '') {
            throw new \Exception('OpenRegister not configured');
        }

        $user  = $this->userSession->getUser();
        $agent = 'system';
        if ($user !== null) {
            $agent = $user->getUID();
        }

        // Build contactmoment object.
        $contactmoment = [
            'channel'     => 'worklog',
            'summary'     => $data['description'] ?? '',
            'duration'    => $data['duration'] ?? '',
            'contactedAt' => $data['date'] ?? date('c'),
            'agent'       => $agent,
        ];

        // Set client or request reference based on entityType.
        if ($entityType === 'client') {
            $contactmoment['client'] = $entityId;
        } else if ($entityType === 'request') {
            $contactmoment['request'] = $entityId;
        }

        // Save to OpenRegister.
        return (array) $objectService->saveObject(
            register: $register,
            schema: $schemaId,
            object: $contactmoment
        );
    }//end createWorklog()

    /**
     * Get worklog entries for an entity.
     *
     * @param string $entityType The entity type (client, request, lead, contact).
     * @param string $entityId   The entity UUID.
     * @param array  $params     Query parameters (_page, _limit).
     *
     * @return array{items: array, total: int, page: int, pages: int, totalDuration: string} The worklog entries.
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-1
     */
    public function getWorklog(string $entityType, string $entityId, array $params): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId      = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');

        if ($register === '' || $schemaId === '') {
            return [
                'items'         => [],
                'total'         => 0,
                'page'          => 1,
                'pages'         => 0,
                'totalDuration' => 'PT0S',
            ];
        }

        // Build query filters.
        $filterParams = ['channel' => 'worklog'];
        if ($entityType === 'client') {
            $filterParams['client'] = $entityId;
        } else if ($entityType === 'request') {
            $filterParams['request'] = $entityId;
        }

        $page  = (int) ($params['_page'] ?? 1);
        $limit = (int) ($params['_limit'] ?? 20);
        $limit = min($limit, 100);

        try {
            $results = $objectService->findObjects(
                register: $register,
                schema: $schemaId,
                params: array_merge(
                    $filterParams,
                    [
                        '_limit'  => 999,
                        '_offset' => 0,
                    ]
                )
            );

            $items = [];
            $totalDurationSeconds = 0;

            foreach ($results as $result) {
                $result  = (array) $result;
                $items[] = [
                    'id'          => $result['id'] ?? $result['uuid'] ?? '',
                    'duration'    => $result['duration'] ?? '',
                    'description' => $result['summary'] ?? '',
                    'date'        => $result['contactedAt'] ?? '',
                    'user'        => $result['agent'] ?? '',
                ];

                // Parse ISO 8601 duration to seconds.
                $duration = $result['duration'] ?? '';
                if ($duration !== '') {
                    $totalDurationSeconds += $this->parseIsoDuration(duration: $duration);
                }
            }

            // Convert seconds back to ISO 8601.
            $totalDuration = $this->secondsToIsoDuration(seconds: $totalDurationSeconds);

            // Pagination.
            $total  = count($items);
            $pages  = (int) ceil($total / $limit);
            $offset = ($page - 1) * $limit;

            $paginatedItems = array_slice($items, $offset, $limit);

            return [
                'items'         => $paginatedItems,
                'total'         => $total,
                'page'          => $page,
                'pages'         => $pages,
                'totalDuration' => $totalDuration,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to fetch worklog',
                [
                    'entityType' => $entityType,
                    'exception'  => $e->getMessage(),
                ]
            );

            return [
                'items'         => [],
                'total'         => 0,
                'page'          => 1,
                'pages'         => 0,
                'totalDuration' => 'PT0S',
            ];
        }//end try
    }//end getWorklog()

    /**
     * Parse ISO 8601 duration string to seconds.
     *
     * @param string $duration The ISO 8601 duration string (e.g., "PT2H30M").
     *
     * @return int The duration in seconds.
     */
    private function parseIsoDuration(string $duration): int
    {
        // Simple ISO 8601 duration parser.
        $result = preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches);
        if ($result === false) {
            return 0;
        }

        $hours   = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }//end parseIsoDuration()

    /**
     * Convert seconds to ISO 8601 duration string.
     *
     * @param int $seconds The duration in seconds.
     *
     * @return string The ISO 8601 duration string.
     */
    private function secondsToIsoDuration(int $seconds): string
    {
        $hours     = (int) floor($seconds / 3600);
        $remaining = $seconds % 3600;
        $minutes   = (int) floor($remaining / 60);
        $secs      = $remaining % 60;

        $duration = 'PT';
        if ($hours > 0) {
            $duration .= $hours.'H';
        }

        if ($minutes > 0) {
            $duration .= $minutes.'M';
        }

        if ($secs > 0) {
            $duration .= $secs.'S';
        }

        if ($duration === 'PT') {
            return 'PT0S';
        }

        return $duration;
    }//end secondsToIsoDuration()

    /**
     * Get the schema config key for a schema type.
     *
     * @param string $paramName The schema type.
     *
     * @return string The config key.
     */
    private function getSchemaConfigKey(string $paramName): string
    {
        return match ($paramName) {
            'contactmoment' => 'contactmoment_schema',
            'task'          => 'task_schema',
            'emailLink'     => 'emailLink_schema',
            'calendarLink'  => 'calendarLink_schema',
            default         => '',
        };
    }//end getSchemaConfigKey()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
