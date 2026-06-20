<?php

/**
 * Pipelinq KccWerkplekService.
 *
 * Server-side aggregation of the KCC agent workspace state — the assigned
 * requests, open tasks, queue counts and the current agent's profile — into
 * a single payload so the unified workspace UI does not have to perform
 * N+1 client-side queries on every page load (REQ-KWP-010 / REQ-KWP-020 /
 * REQ-KWP-050).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * KCC Werkplek workspace state aggregator.
 *
 * Returns a single merged payload for the workspace page (assigned requests,
 * open tasks, queue counts, agent profile) and applies idempotent availability
 * toggles to the user's `agentProfile`.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */
class KccWerkplekService
{
    /**
     * Request statuses considered "open" for the inbox (REQ-KWP-020).
     *
     * @var array<int, string>
     */
    private const OPEN_REQUEST_STATUSES = ['new', 'in_progress'];

    /**
     * Task statuses considered "open" for the inbox (REQ-KWP-020).
     *
     * @var array<int, string>
     */
    private const OPEN_TASK_STATUSES = ['open', 'in_behandeling'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container — used to resolve the
     *                                      OpenRegister `ObjectService` lazily.
     * @param IAppConfig         $appConfig App config — used to read the
     *                                      register slug and schema slugs.
     * @param LoggerInterface    $logger    Logger.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService lazily.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister object service.
     *
     * @throws RuntimeException If the OpenRegister app is not installed.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'OpenRegister ObjectService is not available',
                0,
                $e
            );
        }
    }//end getObjectService()

    /**
     * Read a schema slug from the app config, falling back to a static key.
     *
     * @param string $schemaKey App config key (e.g. `request_schema`).
     *
     * @return string Resolved schema slug, or empty string when missing.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    private function getSchema(string $schemaKey): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
    }//end getSchema()

    /**
     * Read the pipelinq register slug from the app config.
     *
     * @return string Register slug (typically `pipelinq`); empty string when missing.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegister()

    /**
     * Normalise an OpenRegister entity (object or array) to a plain array.
     *
     * @param mixed $object The object or array returned by ObjectService.
     *
     * @return array<string, mixed> Plain associative array.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($object) === true) {
            return (array) $object;
        }

        return [];
    }//end toArray()

    /**
     * Find all objects of a given schema, swallowing OR outages so a partial
     * workspace still renders (REQ-KWP-010 - the page must remain usable).
     *
     * @param string $schemaKey App config key (e.g. `request_schema`).
     *
     * @return array<int, array<string, mixed>> Plain object arrays.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    private function findAllSafe(string $schemaKey): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema(schemaKey: $schemaKey);
        if ($register === '' || $schema === '') {
            $this->logger->info(
                message: '[KccWerkplekService] register or schema not configured',
                context: ['schemaKey' => $schemaKey]
            );
            return [];
        }

        try {
            $results = $this->getObjectService()->findAll(
                config: ['filters' => ['register' => $register, 'schema' => $schema]]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[KccWerkplekService] findAll failed',
                context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($results ?? []) as $result) {
            $out[] = $this->toArray(object: $result);
        }

        return $out;
    }//end findAllSafe()

    /**
     * Build the aggregated workspace state payload for one agent.
     *
     * Returns the agent profile (with sensible defaults if none exists),
     * the assigned-and-open requests, the open tasks assigned to the user
     * and the queue counts (open requests grouped by queue UUID).
     *
     * @param string $userId Nextcloud user UID of the agent.
     *
     * @return array{agentProfile: array<string, mixed>, assignedRequests: array<int, array<string, mixed>>,
     *               openTasks: array<int, array<string, mixed>>, queueCounts: array<string, int>,
     *               queues: array<int, array<string, mixed>>} Workspace state.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    public function getWorkspaceState(string $userId): array
    {
        // Parallel-fetch (single-threaded but each backend round-trip is independent).
        $allRequests = $this->findAllSafe(schemaKey: 'request_schema');
        $allTasks    = $this->findAllSafe(schemaKey: 'task_schema');
        $allAgents   = $this->findAllSafe(schemaKey: 'agentProfile_schema');
        $allQueues   = $this->findAllSafe(schemaKey: 'queue_schema');

        // Filter requests assigned to the current user with an open status.
        $assignedRequests = [];
        foreach ($allRequests as $request) {
            $assignee = (string) ($request['assignee'] ?? '');
            $status   = (string) ($request['status'] ?? '');
            if ($assignee === $userId && in_array($status, self::OPEN_REQUEST_STATUSES, true) === true) {
                $assignedRequests[] = $request;
            }
        }

        // Filter tasks assigned to the current user with an open status.
        $openTasks = [];
        foreach ($allTasks as $task) {
            $assignee = (string) ($task['assigneeUserId'] ?? '');
            $status   = (string) ($task['status'] ?? '');
            if ($assignee === $userId && in_array($status, self::OPEN_TASK_STATUSES, true) === true) {
                $openTasks[] = $task;
            }
        }

        // Resolve the agent profile for this user (fallback: sensible defaults).
        $agentProfile = [
            'userId'        => $userId,
            'isAvailable'   => false,
            'maxConcurrent' => 0,
            'skills'        => [],
        ];
        foreach ($allAgents as $candidate) {
            if ((string) ($candidate['userId'] ?? '') === $userId) {
                $agentProfile = [
                    'id'            => (string) ($candidate['id'] ?? ($candidate['@self']['id'] ?? '')),
                    'userId'        => $userId,
                    'isAvailable'   => (bool) ($candidate['isAvailable'] ?? false),
                    'maxConcurrent' => (int) ($candidate['maxConcurrent'] ?? 0),
                    'skills'        => (array) ($candidate['skills'] ?? []),
                ];
                break;
            }
        }

        // Compute the queue counts across all queues (open requests per queue UUID).
        $queueCounts = [];
        foreach ($allQueues as $queue) {
            $slug = (string) ($queue['@self']['slug'] ?? $queue['slug'] ?? '');
            if ($slug !== '') {
                $queueCounts[$slug] = 0;
            }
        }

        foreach ($allRequests as $request) {
            $status   = (string) ($request['status'] ?? '');
            $queueRef = (string) ($request['queue'] ?? '');
            if ($queueRef === '' || in_array($status, self::OPEN_REQUEST_STATUSES, true) === false) {
                continue;
            }

            // Match queue by id or slug (the request may store either).
            foreach ($allQueues as $queue) {
                $qSlug = (string) ($queue['@self']['slug'] ?? $queue['slug'] ?? '');
                $qId   = (string) ($queue['@self']['id'] ?? $queue['id'] ?? '');
                if ($queueRef === $qSlug || $queueRef === $qId) {
                    $queueCounts[$qSlug] = ($queueCounts[$qSlug] ?? 0) + 1;
                    break;
                }
            }
        }

        // Strip private fields and order queues by sortOrder for the menu.
        $queues = [];
        foreach ($allQueues as $queue) {
            if ((bool) ($queue['isActive'] ?? true) === false) {
                continue;
            }

            $queues[] = [
                'id'          => (string) ($queue['@self']['id'] ?? $queue['id'] ?? ''),
                'slug'        => (string) ($queue['@self']['slug'] ?? $queue['slug'] ?? ''),
                'title'       => (string) ($queue['title'] ?? ''),
                'sortOrder'   => (int) ($queue['sortOrder'] ?? 0),
                'maxCapacity' => $queue['maxCapacity'] ?? null,
            ];
        }

        usort(
            $queues,
            static fn (array $a, array $b): int => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
        );

        return [
            'agentProfile'     => $agentProfile,
            'assignedRequests' => $assignedRequests,
            'openTasks'        => $openTasks,
            'queueCounts'      => $queueCounts,
            'queues'           => $queues,
        ];
    }//end getWorkspaceState()

    /**
     * Set the availability flag on the calling agent's profile.
     *
     * Idempotent: if no profile exists for `$userId` one is created with
     * `userId` and `isAvailable` set. The caller's user ID is the only
     * trusted identity — never accept a user ID from the request body.
     *
     * @param string $userId    Nextcloud user UID of the agent.
     * @param bool   $available Desired availability flag.
     *
     * @return array{userId: string, isAvailable: bool} The updated profile shape.
     *
     * @throws RuntimeException When OR is unavailable or the save fails.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
     */
    public function setAvailability(string $userId, bool $available): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema(schemaKey: 'agentProfile_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('agentProfile schema is not configured');
        }

        $objectService = $this->getObjectService();

        // Try to find an existing profile for this user.
        $existingId   = '';
        $existingData = [];
        try {
            $results = $objectService->findAll(
                config: ['filters' => ['register' => $register, 'schema' => $schema]]
            );
            foreach (($results ?? []) as $result) {
                $arr = $this->toArray(object: $result);
                if ((string) ($arr['userId'] ?? '') === $userId) {
                    $existingData = $arr;
                    $existingId   = (string) ($arr['@self']['id'] ?? $arr['id'] ?? '');
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[KccWerkplekService] setAvailability lookup failed',
                context: ['error' => $e->getMessage()]
            );
        }

        // Merge the existing data with the new flag — preserves skills, maxConcurrent etc.
        $payload           = array_filter(
            $existingData,
            static fn (string $k): bool => $k !== '@self' && $k !== 'id',
            ARRAY_FILTER_USE_KEY
        );
        $payload['userId'] = $userId;
        $payload['isAvailable'] = $available;
        if (isset($payload['maxConcurrent']) === false) {
            $payload['maxConcurrent'] = 1;
        }

        try {
            if ($existingId !== '') {
                $saveId = $existingId;
            } else {
                $saveId = null;
            }

            $objectService->saveObject(
                $payload,
                [],
                $register,
                $schema,
                $saveId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[KccWerkplekService] saveObject failed',
                context: ['error' => $e->getMessage()]
            );
            throw new RuntimeException('Failed to update agent availability', 0, $e);
        }//end try

        return [
            'userId'      => $userId,
            'isAvailable' => $available,
        ];
    }//end setAvailability()
}//end class
