<?php

/**
 * Pipelinq KccWerkplekService.
 *
 * Aggregation service for the KCC (Klant Contact Centrum) agent workspace.
 * Composes existing OpenRegister ObjectService queries into a single
 * workspace-state payload (assigned requests, open tasks, queue counts, the
 * agent's own profile) and toggles the agent's availability. This is NOT a
 * CRUD layer — object CRUD is handled by OpenRegister's generic object API.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Read-aggregation + availability-toggle service for the KCC werkplek.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */
class KccWerkplekService
{
    /**
     * Request statuses considered "open" (still in the agent's inbox).
     *
     * @var array<int, string>
     */
    private const OPEN_REQUEST_STATUSES = ['new', 'in_progress'];

    /**
     * Task statuses considered "open" (still actionable).
     *
     * @var array<int, string>
     */
    private const OPEN_TASK_STATUSES = ['open', 'in_behandeling'];

    /**
     * Upper bound on items fetched per collection.
     */
    private const FETCH_LIMIT = 200;

    /**
     * Default maximum concurrent items when a profile omits maxConcurrent.
     */
    private const DEFAULT_MAX_CONCURRENT = 10;

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig      The app config.
     * @param ContainerInterface $container      The container (for OpenRegister ObjectService).
     * @param RoutingService     $routingService The routing service (reused for workload counts).
     * @param LoggerInterface    $logger         The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private RoutingService $routingService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the aggregated workspace state for a KCC agent.
     *
     * @param string $userId The Nextcloud user UID (derived from IUserSession).
     *
     * @return array<string, mixed> Shape:
     *   {
     *     agentProfile: { isAvailable, maxConcurrent, skills } | null,
     *     assignedRequests: array<int, array<string, mixed>>,
     *     openTasks: array<int, array<string, mixed>>,
     *     queueCounts: array<string, int>,
     *     workload: int
     *   }
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2.1
     */
    public function getWorkspaceState(string $userId): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

        return [
            'agentProfile'     => $this->findAgentProfile(userId: $userId),
            'assignedRequests' => $this->findAssignedRequests(registerId: $registerId, userId: $userId),
            'openTasks'        => $this->findOpenTasks(registerId: $registerId, userId: $userId),
            'queueCounts'      => $this->countQueueRequests(registerId: $registerId),
            'workload'         => $this->routingService->getAgentWorkload(userId: $userId),
        ];
    }//end getWorkspaceState()

    /**
     * Set the calling agent's availability, creating a profile when absent.
     *
     * The userId is ALWAYS the authenticated caller (derived server-side) —
     * an agent can only toggle their own availability (ADR-005).
     *
     * @param string $userId    The Nextcloud user UID (derived from IUserSession).
     * @param bool   $available Whether the agent is available for routing.
     *
     * @return array<string, mixed> The updated/created agentProfile payload.
     *
     * @throws RuntimeException When the register/schema is not configured.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2.1
     */
    public function setAvailability(string $userId, bool $available): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');

        if ($registerId === '' || $schemaId === '') {
            throw new RuntimeException('agentProfile register or schema is not configured');
        }

        $objectService = $this->getObjectService();
        $existing      = $this->loadProfileObject(registerId: $registerId, schemaId: $schemaId, userId: $userId);

        $payload = [
            'userId'      => $userId,
            'isAvailable' => $available,
        ];

        if ($existing !== null) {
            $payload['maxConcurrent'] = (int) ($existing['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT);
            if (isset($existing['skills']) === true) {
                $payload['skills'] = $existing['skills'];
            }

            $objectId = (string) ($existing['id'] ?? ($existing['@self']['id'] ?? ''));
            if ($objectId !== '') {
                $saved = $objectService->updateObject($registerId, $schemaId, $objectId, $payload);
                return $this->normaliseProfile(profile: (array) $saved);
            }
        }

        $payload['maxConcurrent'] = self::DEFAULT_MAX_CONCURRENT;
        $saved = $objectService->saveObject($registerId, $schemaId, $payload);

        return $this->normaliseProfile(profile: (array) $saved);
    }//end setAvailability()

    /**
     * Find and normalise the agent's own profile.
     *
     * @param string $userId The Nextcloud user UID.
     *
     * @return array<string, mixed>|null The profile payload, or null if none.
     */
    private function findAgentProfile(string $userId): ?array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');

        if ($registerId === '' || $schemaId === '') {
            return null;
        }

        $profile = $this->loadProfileObject(registerId: $registerId, schemaId: $schemaId, userId: $userId);
        if ($profile === null) {
            return null;
        }

        return $this->normaliseProfile(profile: $profile);
    }//end findAgentProfile()

    /**
     * Load the raw agentProfile object for a user, or null when absent.
     *
     * @param string $registerId The configured register ID.
     * @param string $schemaId   The agentProfile schema ID.
     * @param string $userId     The Nextcloud user UID.
     *
     * @return array<string, mixed>|null The raw profile, or null.
     */
    private function loadProfileObject(string $registerId, string $schemaId, string $userId): ?array
    {
        try {
            $profiles = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'userId'   => $userId,
                    ],
                    'limit'   => 1,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'KccWerkplekService: failed to load agent profile',
                ['exception' => $e->getMessage(), 'userId' => $userId]
            );
            return null;
        }

        foreach ($profiles as $profile) {
            $profile = (array) $profile;
            if ((string) ($profile['userId'] ?? '') === $userId) {
                return $profile;
            }
        }

        return null;
    }//end loadProfileObject()

    /**
     * Reduce an agentProfile object to the workspace payload shape.
     *
     * @param array<string, mixed> $profile The raw profile object.
     *
     * @return array<string, mixed> Normalised { isAvailable, maxConcurrent, skills }.
     */
    private function normaliseProfile(array $profile): array
    {
        return [
            'isAvailable'   => (bool) ($profile['isAvailable'] ?? false),
            'maxConcurrent' => (int) ($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT),
            'skills'        => (array) ($profile['skills'] ?? []),
        ];
    }//end normaliseProfile()

    /**
     * Find open requests assigned to the agent.
     *
     * @param string $registerId The configured register ID.
     * @param string $userId     The Nextcloud user UID.
     *
     * @return array<int, array<string, mixed>> The inbox request rows.
     */
    private function findAssignedRequests(string $registerId, string $userId): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        try {
            $requests = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'assignee' => $userId,
                    ],
                    'limit'   => self::FETCH_LIMIT,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'KccWerkplekService: failed to load assigned requests',
                ['exception' => $e->getMessage(), 'userId' => $userId]
            );
            return [];
        }

        $rows = [];
        foreach ($requests as $request) {
            $request = (array) $request;
            $status  = strtolower((string) ($request['status'] ?? ''));
            if (in_array($status, self::OPEN_REQUEST_STATUSES, true) === false) {
                continue;
            }

            $rows[] = [
                'id'        => (string) ($request['id'] ?? ($request['@self']['id'] ?? '')),
                'title'     => (string) ($request['title'] ?? ''),
                'priority'  => (string) ($request['priority'] ?? 'normal'),
                'channel'   => (string) ($request['channel'] ?? ''),
                'status'    => $status,
                'createdAt' => (string) ($request['requestedAt'] ?? ($request['@self']['created'] ?? '')),
            ];
        }//end foreach

        return $rows;
    }//end findAssignedRequests()

    /**
     * Find open tasks assigned to the agent.
     *
     * @param string $registerId The configured register ID.
     * @param string $userId     The Nextcloud user UID.
     *
     * @return array<int, array<string, mixed>> The inbox task rows.
     */
    private function findOpenTasks(string $registerId, string $userId): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        try {
            $tasks = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register'       => $registerId,
                        'schema'         => $schemaId,
                        'assigneeUserId' => $userId,
                    ],
                    'limit'   => self::FETCH_LIMIT,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'KccWerkplekService: failed to load open tasks',
                ['exception' => $e->getMessage(), 'userId' => $userId]
            );
            return [];
        }

        $rows = [];
        foreach ($tasks as $task) {
            $task   = (array) $task;
            $status = strtolower((string) ($task['status'] ?? ''));
            if (in_array($status, self::OPEN_TASK_STATUSES, true) === false) {
                continue;
            }

            $rows[] = [
                'id'       => (string) ($task['id'] ?? ($task['@self']['id'] ?? '')),
                'subject'  => (string) ($task['subject'] ?? ''),
                'type'     => (string) ($task['type'] ?? ''),
                'priority' => (string) ($task['priority'] ?? 'normal'),
                'deadline' => (string) ($task['deadline'] ?? ''),
                'status'   => $status,
            ];
        }//end foreach

        return $rows;
    }//end findOpenTasks()

    /**
     * Count open requests per queue for the workload-routing display.
     *
     * @param string $registerId The configured register ID.
     *
     * @return array<string, int> Map of queue value => open-request count.
     */
    private function countQueueRequests(string $registerId): array
    {
        $requestSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        if ($registerId === '' || $requestSchemaId === '') {
            return [];
        }

        try {
            $requests = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $requestSchemaId,
                    ],
                    'limit'   => 999,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'KccWerkplekService: failed to count queue requests',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $counts = [];
        foreach ($requests as $request) {
            $request = (array) $request;
            $status  = strtolower((string) ($request['status'] ?? ''));
            if (in_array($status, self::OPEN_REQUEST_STATUSES, true) === false) {
                continue;
            }

            $queue = (string) ($request['queue'] ?? '');
            if ($queue === '') {
                continue;
            }

            $counts[$queue] = (($counts[$queue] ?? 0) + 1);
        }//end foreach

        return $counts;
    }//end countQueueRequests()

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
