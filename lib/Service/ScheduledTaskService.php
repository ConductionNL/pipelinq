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

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for the Schedules API.
 *
 * Provides CRUD and lifecycle processing for scheduled tasks backed by the
 * `task` schema in OpenRegister. Responsible for time-window queries,
 * deadline-driven status transitions, and per-object mutation authorisation.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Residual after the hot
 *  methods (getScheduledTasks / createScheduledTask / processScheduledTasks)
 *  were decomposed into small single-responsibility helpers; the class WMC is
 *  now the sum of many readable methods rather than a few complex ones.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  task-lifecycle service legitimately needs (config, session, groups,
 *  notifications, OR container, logger, plus DateTime/exception value types).
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
     * Task fields that any authenticated user may modify.
     *
     * All other incoming keys are silently stripped before the merge to
     * prevent mass-assignment of admin-only or system fields.
     * Admin-only fields (status, assigneeUserId, assigneeGroupId, attempts,
     * createdAt, completedAt) are handled separately in the controller.
     *
     * @var array<string>
     */
    public const MUTABLE_FIELDS = [
        'type',
        'subject',
        'description',
        'priority',
        'deadline',
        'clientId',
        'requestId',
        'contactMomentSummary',
        'callbackPhoneNumber',
        'preferredTimeSlot',
        'resultText',
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

        $filters = $this->buildTaskFilters(registerId: $registerId, schemaId: $schemaId, params: $params);

        $items = [];
        $total = 0;

        try {
            // Fetch the total count without pagination so the envelope
            // reflects the true result-set size, not just the page size.
            $allItems = $this->getObjectService()->findAll(
                ['filters' => $filters]
            );
            $total    = count($allItems);

            // Fetch the paginated page.
            $items = $this->getObjectService()->findAll(
                [
                    'filters' => $filters,
                    'limit'   => $limit,
                    'offset'  => (($page - 1) * $limit),
                    'order'   => ['deadline' => 'ASC'],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: findAll failed',
                ['exception' => $e]
            );
        }//end try

        $pages = 0;
        if ($total > 0) {
            $pages = (int) ceil($total / $limit);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }//end getScheduledTasks()

    /**
     * Build the OpenRegister filter array for a scheduled-task list query.
     *
     * Applies the register/schema scope plus the optional status, assignee and
     * deadline-window filters from the request params.
     *
     * @param string              $registerId The configured register ID.
     * @param string              $schemaId   The task schema ID.
     * @param array<string,mixed> $params     The request filter params.
     *
     * @return array<string,mixed> The assembled filter array.
     */
    private function buildTaskFilters(string $registerId, string $schemaId, array $params): array
    {
        $filters = [
            'register' => $registerId,
            'schema'   => $schemaId,
        ];

        foreach (['status', 'assigneeUserId', 'assigneeGroupId'] as $key) {
            if (isset($params[$key]) === true && $params[$key] !== '') {
                $filters[$key] = $params[$key];
            }
        }

        return $this->applyDeadlineWindow(filters: $filters, params: $params);
    }//end buildTaskFilters()

    /**
     * Apply the optional deadline from/to window onto an existing filter array.
     *
     * @param array<string,mixed> $filters The filters built so far.
     * @param array<string,mixed> $params  The request params (from, to).
     *
     * @return array<string,mixed> The filters with any deadline window applied.
     */
    private function applyDeadlineWindow(array $filters, array $params): array
    {
        if (isset($params['from']) === true && $params['from'] !== '') {
            $filters['deadline'] = ['>=' => $params['from']];
        }

        if (isset($params['to']) === true && $params['to'] !== '') {
            if (isset($filters['deadline']) === false || is_array($filters['deadline']) === false) {
                $filters['deadline'] = [];
            }

            $filters['deadline']['<='] = $params['to'];
        }

        return $filters;
    }//end applyDeadlineWindow()

    /**
     * Fetch a single scheduled task by ID.
     *
     * @param string $id The task UUID.
     *
     * @return array<string, mixed> The task object.
     *
     * @throws RuntimeException If the task is not found.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function getScheduledTask(string $id): array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            throw new RuntimeException('Task not found');
        }

        try {
            $object = $this->getObjectService()->find(
                $id,
                [],
                false,
                $registerId,
                $schemaId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: find failed',
                ['exception' => $e, 'id' => $id]
            );
            throw new RuntimeException('Task not found');
        }

        if ($object === null) {
            throw new RuntimeException('Task not found');
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
     * @throws InvalidArgumentException On validation failure.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function createScheduledTask(array $data): array
    {
        $this->validateTaskInput(data: $data);

        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            throw new RuntimeException('Register or task schema not configured');
        }

        $user = $this->userSession->getUser();
        $data['createdBy'] = 'system';
        if ($user !== null) {
            $data['createdBy'] = $user->getUID();
        }

        if (isset($data['status']) === false || $data['status'] === '') {
            $data['status'] = 'open';
        }

        $saved = $this->getObjectService()->saveObject(
            $data,
            [],
            $registerId,
            $schemaId,
            null
        );

        return $this->normalizeToArray(object: $saved);
    }//end createScheduledTask()

    /**
     * Validate the required fields and deadline format for a new task.
     *
     * @param array<string,mixed> $data The incoming task data.
     *
     * @return void
     *
     * @throws InvalidArgumentException When type/subject/deadline are missing or invalid.
     */
    private function validateTaskInput(array $data): void
    {
        if (isset($data['type']) === false
            || in_array($data['type'], self::VALID_TYPES, true) === false
        ) {
            throw new InvalidArgumentException('Invalid input');
        }

        if (isset($data['subject']) === false || trim((string) $data['subject']) === '') {
            throw new InvalidArgumentException('Invalid input');
        }

        if (isset($data['deadline']) === false || trim((string) $data['deadline']) === '') {
            throw new InvalidArgumentException('Invalid input');
        }

        // Require ISO-8601 format (YYYY-MM-DD or YYYY-MM-DDThh:mm[:ss][Z])
        // to prevent relative expressions like "yesterday" or "+2 weeks" from
        // passing silently and producing server-timezone-dependent deadlines.
        if ($this->isValidIso8601Deadline(deadline: (string) $data['deadline']) === false) {
            throw new InvalidArgumentException('Deadline must be a valid ISO-8601 date (e.g. 2025-12-31 or 2025-12-31T14:00:00Z)');
        }
    }//end validateTaskInput()

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

        // Strip any fields not explicitly on the allowlist (MUTABLE_FIELDS +
        // admin-only fields already filtered by the controller).  This prevents
        // mass-assignment of system/immutable fields like createdBy, uuid, etc.
        $allowedKeys = array_merge(self::MUTABLE_FIELDS, ['status', 'assigneeUserId', 'assigneeGroupId', 'createdAt', 'completedAt', 'attempts']);
        $data        = array_intersect_key($data, array_flip($allowedKeys));

        $merged       = array_merge($existing, $data);
        $merged['id'] = $id;

        [$registerId, $schemaId] = $this->getRegisterAndSchema();

        // Fail closed on an unconfigured register/schema. Today this is
        // defence in depth rather than a live hole: getScheduledTask() above
        // already throws on '' before we get here. It is stated locally because
        // the write MUST NOT run on an empty id — an empty id is not the same
        // as "no id" to ObjectService, which skips setRegister()/setSchema()
        // for an empty value and so writes the task into whatever
        // register/schema context an earlier call in the same request left
        // behind. The guard must not depend on a read further up staying put.
        if ($registerId === '' || $schemaId === '') {
            throw new RuntimeException('Task register or schema is not configured.');
        }

        $saved = $this->getObjectService()->saveObject(
            $merged,
            [],
            $registerId,
            $schemaId,
            $id
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
        $this->getObjectService()->deleteObject($id);
    }//end deleteScheduledTask()

    /**
     * Find open tasks whose deadline is in the past (overdue).
     *
     * Returns tasks with `status = open` and `deadline < now`, ordered by
     * deadline ascending. These are candidates for the `verlopen` transition.
     *
     * @return array<int, array<string, mixed>> The overdue tasks.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-1
     */
    public function getOverdueTasks(): array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        $now = new DateTimeImmutable('now');

        try {
            $items = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'status'   => 'open',
                        'deadline' => [
                            '<' => $now->format(DateTimeInterface::ATOM),
                        ],
                    ],
                    'limit'   => 100,
                    'order'   => ['deadline' => 'ASC'],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: getOverdueTasks failed',
                ['exception' => $e]
            );
            return [];
        }//end try

        $normalised = [];
        foreach ($items as $item) {
            $normalised[] = $this->normalizeToArray(object: $item);
        }

        return $normalised;
    }//end getOverdueTasks()

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

        $now    = new DateTimeImmutable('now');
        $cutoff = $now->modify(sprintf('+%d minutes', $windowMinutes));

        try {
            $items = $this->getObjectService()->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'status'   => 'open',
                        'deadline' => [
                            '>=' => $now->format(DateTimeInterface::ATOM),
                            '<=' => $cutoff->format(DateTimeInterface::ATOM),
                        ],
                    ],
                    'limit'   => 100,
                    'order'   => ['deadline' => 'ASC'],
                ]
            );
        } catch (Throwable $e) {
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
        // Due-soon tasks (deadline within the next 4 hours) → notify + in_behandeling.
        $dueSoon = $this->getPendingTasks(windowMinutes: self::EXPIRY_THRESHOLD_MINUTES);
        // Overdue tasks (deadline already passed) → mark verlopen.
        $overdue    = $this->getOverdueTasks();
        $candidates = array_merge($overdue, $dueSoon);

        $now       = new DateTimeImmutable('now');
        $expiryCut = $now->modify('-4 hours');

        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return;
        }

        foreach ($candidates as $task) {
            $this->processSingleTask(
                task: $task,
                now: $now,
                expiryCut: $expiryCut,
                registerId: $registerId,
                schemaId: $schemaId
            );
        }//end foreach
    }//end processScheduledTasks()

    /**
     * Apply the deadline-driven transition to a single candidate task.
     *
     * Open tasks past their deadline are marked `verlopen` (when overdue beyond
     * the expiry cut) or `in_behandeling` with an assignee notification (when
     * within the cut). Non-open or not-yet-due tasks are skipped.
     *
     * @param array<string,mixed> $task       The candidate task.
     * @param DateTimeImmutable   $now        The reference "now".
     * @param DateTimeImmutable   $expiryCut  The expiry threshold timestamp.
     * @param string              $registerId The configured register ID.
     * @param string              $schemaId   The task schema ID.
     *
     * @return void
     */
    private function processSingleTask(array $task, DateTimeImmutable $now, DateTimeImmutable $expiryCut, string $registerId, string $schemaId): void
    {
        if (($task['status'] ?? '') !== 'open') {
            return;
        }

        $deadlineRaw = $task['deadline'] ?? '';
        if ($deadlineRaw === '') {
            return;
        }

        try {
            $deadline = new DateTimeImmutable((string) $deadlineRaw);
        } catch (Throwable $e) {
            return;
        }

        if ($deadline > $now) {
            // Not due yet.
            return;
        }

        $task = $this->applyDeadlineTransition(
            task: $task,
            deadline: $deadline,
            expiryCut: $expiryCut,
            timestamp: $now->format(DateTimeInterface::ATOM)
        );

        $this->persistProcessedTask(task: $task, registerId: $registerId, schemaId: $schemaId);
    }//end processSingleTask()

    /**
     * Compute the new status + appended attempt for a due task.
     *
     * Overdue-beyond-cut tasks become `verlopen` (expired attempt); tasks still
     * within the cut become `in_behandeling` (notified attempt) and trigger an
     * assignee notification.
     *
     * @param array<string,mixed> $task      The due task.
     * @param DateTimeImmutable   $deadline  The parsed deadline.
     * @param DateTimeImmutable   $expiryCut The expiry threshold.
     * @param string              $timestamp The ATOM timestamp for the attempt.
     *
     * @return array<string,mixed> The task with updated status + attempts.
     */
    private function applyDeadlineTransition(array $task, DateTimeImmutable $deadline, DateTimeImmutable $expiryCut, string $timestamp): array
    {
        $attempts = $task['attempts'] ?? [];
        if (is_array($attempts) === false) {
            $attempts = [];
        }

        if ($deadline < $expiryCut) {
            $task['status'] = 'verlopen';
            $attempts[]     = [
                'timestamp' => $timestamp,
                'result'    => 'expired',
            ];

            $this->notifyTaskExpired(task: $task);
        }

        if ($deadline >= $expiryCut) {
            $task['status'] = 'in_behandeling';
            $attempts[]     = [
                'timestamp' => $timestamp,
                'result'    => 'notified',
            ];

            $this->notifyTaskAssignee(task: $task);
        }

        $task['attempts'] = $attempts;

        return $task;
    }//end applyDeadlineTransition()

    /**
     * Persist a processed task, logging and swallowing save failures.
     *
     * @param array<string,mixed> $task       The task to save.
     * @param string              $registerId The configured register ID.
     * @param string              $schemaId   The task schema ID.
     *
     * @return void
     */
    private function persistProcessedTask(array $task, string $registerId, string $schemaId): void
    {
        try {
            $this->getObjectService()->saveObject(
                $task,
                [],
                $registerId,
                $schemaId,
                (string) ($task['id'] ?? '')
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: task update failed',
                ['exception' => $e, 'id' => $task['id'] ?? null]
            );
        }
    }//end persistProcessedTask()

    /**
     * Notify a task's assignee that it has moved into processing.
     *
     * No-op when the task has no assignee. Notification failures are logged and
     * swallowed so they do not abort the batch run.
     *
     * @param array<string,mixed> $task The task being transitioned.
     *
     * @return void
     */
    private function notifyTaskAssignee(array $task): void
    {
        $assignee = $task['assigneeUserId'] ?? '';
        if ($assignee === '') {
            return;
        }

        try {
            $this->notificationService->notifyAssignment(
                entityType: 'task',
                title: (string) ($task['subject'] ?? 'Scheduled task'),
                assigneeUserId: (string) $assignee,
                objectId: (string) ($task['id'] ?? ''),
                author: 'system'
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: notification dispatch failed',
                ['exception' => $e]
            );
        }
    }//end notifyTaskAssignee()

    /**
     * Escalate an expired task to its assignee.
     *
     * Fired when an open task passes its deadline beyond the expiry cut and is
     * transitioned to `verlopen`. No-op when the task has no assignee.
     * Notification failures are logged and swallowed so they never abort the
     * batch run.
     *
     * @param array<string,mixed> $task The task being expired.
     *
     * @return void
     *
     * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
     */
    private function notifyTaskExpired(array $task): void
    {
        $assignee = $task['assigneeUserId'] ?? '';
        if ($assignee === '') {
            return;
        }

        try {
            $this->notificationService->notifyTaskExpired(
                title: (string) ($task['subject'] ?? 'Scheduled task'),
                userId: (string) $assignee,
                objectId: (string) ($task['id'] ?? ''),
                deadline: (string) ($task['deadline'] ?? '')
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'ScheduledTaskService: expiry notification dispatch failed',
                ['exception' => $e]
            );
        }
    }//end notifyTaskExpired()

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
     * Validate that a deadline string is an ISO-8601 date or datetime.
     *
     * Accepts YYYY-MM-DD and YYYY-MM-DDThh:mm (with optional seconds and Z/offset).
     * Rejects relative expressions ("yesterday", "+2 weeks") that strtotime accepts,
     * preventing server-timezone-dependent deadline values.
     *
     * @param string $deadline The raw deadline string.
     *
     * @return bool True when the format is a strict ISO-8601 date(-time).
     */
    private function isValidIso8601Deadline(string $deadline): bool
    {
        // YYYY-MM-DD (date only).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
            return true;
        }

        // YYYY-MM-DDThh:mm, YYYY-MM-DDThh:mm:ss, with optional Z or offset.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$/', $deadline) === 1) {
            return true;
        }

        return false;
    }//end isValidIso8601Deadline()

    /**
     * Read configured register and task schema IDs.
     *
     * Fails closed: '' on either id means "unconfigured", and every caller
     * refuses the OpenRegister call on it. An empty id must never be handed to
     * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
     * value, so the query silently inherits whatever context an earlier call in
     * the same request left on the shared service instance. The empty case is
     * logged so an unprovisioned instance is visible rather than silent.
     *
     * @return array{0: string, 1: string} [register, schema] tuple, each ''
     *                                     when unconfigured.
     */
    private function getRegisterAndSchema(): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning(
                'Pipelinq: register/task_schema not configured; OpenRegister calls are refused, not run unscoped'
            );
        }

        return [$registerId, $schemaId];
    }//end getRegisterAndSchema()

    /**
     * Forward the Accept-Language header to OpenRegister's LanguageService.
     *
     * Parses the highest-priority BCP-47 tag from the raw header value and
     * sets it on OR's request-scoped LanguageService so that ObjectService
     * calls return translated field values when the preferred language is
     * available (ADR-025, task 9.3).
     *
     * No-ops silently when OR's LanguageService is unavailable (pre-ship).
     *
     * @param string $acceptLanguage The raw Accept-Language header value.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
     */
    public function applyAcceptLanguage(string $acceptLanguage): void
    {
        if ($acceptLanguage === '') {
            return;
        }

        try {
            $languageService = $this->container->get('OCA\OpenRegister\Service\LanguageService');
            $lang            = $this->parsePreferredLanguage(headerValue: $acceptLanguage);
            $languageService->setPreferredLanguage(language: $lang);
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: 'applyAcceptLanguage: OR LanguageService unavailable.',
                context: ['exception' => $e->getMessage()]
            );
        }
    }//end applyAcceptLanguage()

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
     * Extract the highest-priority BCP-47 tag from an Accept-Language header.
     *
     * Parses "nl-NL,nl;q=0.9,en;q=0.8" → "nl-NL".
     *
     * @param string $headerValue The raw Accept-Language header value.
     *
     * @return string The preferred language tag (e.g. "nl" or "en-US").
     */
    private function parsePreferredLanguage(string $headerValue): string
    {
        $parts = explode(separator: ',', string: $headerValue);
        $first = trim(string: explode(separator: ';', string: $parts[0])[0]);

        if ($first !== '') {
            return $first;
        }

        return 'nl';
    }//end parsePreferredLanguage()

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
