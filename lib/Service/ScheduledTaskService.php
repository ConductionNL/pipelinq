<?php

/**
 * Pipelinq ScheduledTaskService.
 *
 * Service for schedule-aware task CRUD and processing logic.
 * Uses the existing OpenRegister `task` schema and exposes
 * REST-friendly operations consumed by SchedulesController and
 * ScheduledTaskJob.
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
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the Schedules API.
 *
 * Provides CRUD and lifecycle processing for scheduled tasks backed by the
 * `task` schema in OpenRegister. Responsible for time-window queries,
 * deadline-driven status transitions, and per-object mutation authorisation.
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 */
class ScheduledTaskService
{
    /**
     * Valid task types.
     *
     * @var array<string>
     */
    public const VALID_TYPES = [
        'terugbelverzoek',
        'opvolgtaak',
        'informatievraag',
    ];

    /**
     * Maximum window in minutes for pending-task queries (24 hours).
     *
     * @var int
     */
    private const MAX_WINDOW_MINUTES = 1440;

    /**
     * Threshold in minutes for marking overdue open tasks as `verlopen`.
     *
     * @var int
     */
    private const EXPIRY_THRESHOLD_MINUTES = 240;

    /**
     * Constructor.
     *
     * @param IAppConfig          $appConfig           App config (for register/schema IDs).
     * @param IUserSession        $userSession         User session (createdBy derivation).
     * @param IGroupManager       $groupManager        Group manager (admin + group checks).
     * @param NotificationService $notificationService Notification dispatch.
     * @param ContainerInterface  $container           Container for ObjectService lookup.
     * @param LoggerInterface     $logger              Logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly NotificationService $notificationService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List scheduled tasks with optional filters and pagination.
     *
     * Supported filters: `status`, `assigneeUserId`, `assigneeGroupId`,
     * `from` (deadline ≥), `to` (deadline ≤). Pagination via `_page` and `_limit`.
     *
     * @param array<string, mixed> $params Filter and pagination parameters.
     *
     * @return array{items: array<int, mixed>, total: int, page: int, pages: int} List envelope.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function getScheduledTasks(array $params): array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return [
                'items' => [],
                'total' => 0,
                'page'  => 1,
                'pages' => 0,
            ];
        }

        $page  = max(1, (int) ($params['_page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['_limit'] ?? 20)));

        $filters = [
            'register' => $registerId,
            'schema'   => $schemaId,
        ];

        if (isset($params['status']) === true && $params['status'] !== '') {
            $filters['status'] = $params['status'];
        }

        if (isset($params['assigneeUserId']) === true && $params['assigneeUserId'] !== '') {
            $filters['assigneeUserId'] = $params['assigneeUserId'];
        }

        if (isset($params['assigneeGroupId']) === true && $params['assigneeGroupId'] !== '') {
            $filters['assigneeGroupId'] = $params['assigneeGroupId'];
        }

        if (isset($params['from']) === true && $params['from'] !== '') {
            $filters['deadline'] = ['>=' => $params['from']];
        }

        if (isset($params['to']) === true && $params['to'] !== '') {
            if (isset($filters['deadline']) === true && is_array($filters['deadline']) === true) {
                $filters['deadline']['<='] = $params['to'];
            } else {
                $filters['deadline'] = ['<=' => $params['to']];
            }
        }

        try {
            $items = $this->getObjectService()->findAll(
                [
                    'filters' => $filters,
                    'limit'   => $limit,
                    'offset'  => (($page - 1) * $limit),
                    'order'   => ['deadline' => 'ASC'],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: findAll failed',
                ['exception' => $e]
            );
            $items = [];
        }

        $total = count($items);
        $pages = (int) ceil($total / $limit);

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }//end getScheduledTasks()

    /**
     * Fetch a single scheduled task by ID.
     *
     * @param string $id The task UUID.
     *
     * @return array<string, mixed> The task object.
     *
     * @throws \RuntimeException If the task is not found.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function getScheduledTask(string $id): array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            throw new \RuntimeException('Task not found');
        }

        try {
            $object = $this->getObjectService()->findObject(
                $id,
                $registerId,
                $schemaId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: findObject failed',
                ['exception' => $e, 'id' => $id]
            );
            throw new \RuntimeException('Task not found');
        }

        if ($object === null) {
            throw new \RuntimeException('Task not found');
        }

        return $this->normalizeToArray(object: $object);
    }//end getScheduledTask()

    /**
     * Create a new scheduled task.
     *
     * Required fields: `type`, `subject`, `deadline`. The `createdBy` field is
     * always derived from the authenticated session and may not be overridden
     * by the request body.
     *
     * @param array<string, mixed> $data The task data.
     *
     * @return array<string, mixed> The saved task object.
     *
     * @throws \InvalidArgumentException On validation failure.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function createScheduledTask(array $data): array
    {
        if (isset($data['type']) === false
            || in_array($data['type'], self::VALID_TYPES, true) === false
        ) {
            throw new \InvalidArgumentException('Invalid input');
        }

        if (isset($data['subject']) === false || trim((string) $data['subject']) === '') {
            throw new \InvalidArgumentException('Invalid input');
        }

        if (isset($data['deadline']) === false || trim((string) $data['deadline']) === '') {
            throw new \InvalidArgumentException('Invalid input');
        }

        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            throw new \RuntimeException('Register or task schema not configured');
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $data['createdBy'] = $user->getUID();
        } else {
            $data['createdBy'] = 'system';
        }

        if (isset($data['status']) === false || $data['status'] === '') {
            $data['status'] = 'open';
        }

        $saved = $this->getObjectService()->saveObject(
            $data,
            [],
            $registerId,
            $schemaId,
            null,
            _rbac: false,
            _multitenancy: false
        );

        return $this->normalizeToArray(object: $saved);
    }//end createScheduledTask()

    /**
     * Update an existing scheduled task.
     *
     * Merges `$data` into the existing task. The `createdBy` field is always
     * stripped from incoming data and preserved from the existing record.
     *
     * @param string               $id   The task UUID.
     * @param array<string, mixed> $data Partial update data.
     *
     * @return array<string, mixed> The updated task object.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function updateScheduledTask(string $id, array $data): array
    {
        $existing = $this->getScheduledTask(id: $id);
        unset($data['createdBy']);

        $merged       = array_merge($existing, $data);
        $merged['id'] = $id;

        [$registerId, $schemaId] = $this->getRegisterAndSchema();

        $saved = $this->getObjectService()->saveObject(
            $merged,
            [],
            $registerId,
            $schemaId,
            $id,
            _rbac: false,
            _multitenancy: false
        );

        return $this->normalizeToArray(object: $saved);
    }//end updateScheduledTask()

    /**
     * Delete a scheduled task.
     *
     * @param string $id The task UUID.
     *
     * @return void
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function deleteScheduledTask(string $id): void
    {
        $this->getObjectService()->deleteObject(
            $id,
            _rbac: false,
            _multitenancy: false
        );
    }//end deleteScheduledTask()

    /**
     * Find open tasks with a deadline inside the next `$windowMinutes`.
     *
     * The window is capped at 1440 minutes (24 hours).
     *
     * @param int $windowMinutes The look-ahead window in minutes.
     *
     * @return array<int, array<string, mixed>> The matching tasks.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function getPendingTasks(int $windowMinutes=60): array
    {
        if ($windowMinutes > self::MAX_WINDOW_MINUTES) {
            $windowMinutes = self::MAX_WINDOW_MINUTES;
        }

        if ($windowMinutes < 1) {
            $windowMinutes = 1;
        }

        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        $now    = new \DateTimeImmutable('now');
        $cutoff = $now->modify(sprintf('+%d minutes', $windowMinutes));

        try {
            $items = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'status'   => 'open',
                        'deadline' => [
                            '>=' => $now->format(\DateTimeInterface::ATOM),
                            '<=' => $cutoff->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                    'limit'   => 100,
                    'order'   => ['deadline' => 'ASC'],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: getPendingTasks failed',
                ['exception' => $e]
            );
            return [];
        }//end try

        $normalised = [];
        foreach ($items as $item) {
            $normalised[] = $this->normalizeToArray(object: $item);
        }

        return $normalised;
    }//end getPendingTasks()

    /**
     * Process all due scheduled tasks.
     *
     * Tasks with `deadline` ≤ now and `status = open` are transitioned:
     * - `deadline` > 4 hours ago: notify assignee, set status `in_behandeling`, log attempt.
     * - `deadline` ≤ 4 hours ago: set status `verlopen`, log expiry attempt.
     *
     * Tasks already `in_behandeling`, `afgerond`, or `verlopen` are skipped.
     *
     * @return void
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function processScheduledTasks(): void
    {
        $candidates = $this->getPendingTasks(windowMinutes: self::EXPIRY_THRESHOLD_MINUTES);
        $now        = new \DateTimeImmutable('now');
        $expiryCut  = $now->modify('-4 hours');

        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return;
        }

        foreach ($candidates as $task) {
            $status = $task['status'] ?? '';
            if (in_array($status, ['in_behandeling', 'afgerond', 'verlopen'], true) === true) {
                continue;
            }

            if ($status !== 'open') {
                continue;
            }

            $deadlineRaw = $task['deadline'] ?? '';
            if ($deadlineRaw === '') {
                continue;
            }

            try {
                $deadline = new \DateTimeImmutable((string) $deadlineRaw);
            } catch (\Throwable $e) {
                continue;
            }

            if ($deadline > $now) {
                // Not due yet.
                continue;
            }

            $attempts = $task['attempts'] ?? [];
            if (is_array($attempts) === false) {
                $attempts = [];
            }

            $timestamp = $now->format(\DateTimeInterface::ATOM);

            if ($deadline < $expiryCut) {
                $task['status'] = 'verlopen';
                $attempts[]     = [
                    'timestamp' => $timestamp,
                    'result'    => 'expired',
                ];
            } else {
                $task['status'] = 'in_behandeling';
                $attempts[]     = [
                    'timestamp' => $timestamp,
                    'result'    => 'notified',
                ];

                $assignee = $task['assigneeUserId'] ?? '';
                if ($assignee !== '') {
                    try {
                        $this->notificationService->notifyAssignment(
                            entityType: 'task',
                            title: (string) ($task['subject'] ?? 'Scheduled task'),
                            assigneeUserId: (string) $assignee,
                            objectId: (string) ($task['id'] ?? ''),
                            author: 'system'
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            'ScheduledTaskService: notification dispatch failed',
                            ['exception' => $e]
                        );
                    }
                }
            }//end if

            $task['attempts'] = $attempts;

            try {
                $this->getObjectService()->saveObject(
                    $task,
                    [],
                    $registerId,
                    $schemaId,
                    (string) ($task['id'] ?? ''),
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'ScheduledTaskService: task update failed',
                    ['exception' => $e, 'id' => $task['id'] ?? null]
                );
            }
        }//end foreach
    }//end processScheduledTasks()

    /**
     * Authorise a mutation on a task.
     *
     * Allowed if the user is the assignee, a member of the assigned group, or
     * an administrator. Otherwise throws an OCSForbiddenException.
     *
     * @param array<string, mixed> $task   The task object.
     * @param string               $userId The acting user ID.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user is not authorised.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function authorizeTaskMutation(array $task, string $userId): void
    {
        if ($userId === '') {
            throw new OCSForbiddenException('Not authorized');
        }

        $assigneeUserId = (string) ($task['assigneeUserId'] ?? '');
        if ($assigneeUserId !== '' && $assigneeUserId === $userId) {
            return;
        }

        $assigneeGroupId = (string) ($task['assigneeGroupId'] ?? '');
        if ($assigneeGroupId !== ''
            && $this->groupManager->isInGroup($userId, $assigneeGroupId) === true
        ) {
            return;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return;
        }

        throw new OCSForbiddenException('Not authorized');
    }//end authorizeTaskMutation()

    /**
     * Read configured register and task schema IDs.
     *
     * @return array{0: string, 1: string} [register, schema] tuple.
     */
    private function getRegisterAndSchema(): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        return [$registerId, $schemaId];
    }//end getRegisterAndSchema()

    /**
     * Lazy ObjectService resolver to avoid a hard dependency on OpenRegister.
     *
     * @return object The OpenRegister ObjectService instance.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Normalise an ObjectEntity-or-array to a plain array.
     *
     * @param mixed $object The raw result from ObjectService.
     *
     * @return array<string, mixed> The serialised array form.
     */
    private function normalizeToArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $serialised = $object->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($object, 'toArray') === true) {
                $array = $object->toArray();
                if (is_array($array) === true) {
                    return $array;
                }
            }
        }

        return [];
    }//end normalizeToArray()
}//end class
