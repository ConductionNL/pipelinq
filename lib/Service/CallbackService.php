<?php

/**
 * Pipelinq CallbackService.
 *
 * Service for callback request (terugbelverzoek) business logic.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;
use DateTime;
use Psr\Log\LoggerInterface;

/**
 * Service for callback request operations.
 *
 * Handles attempt logging, claim validation, status transitions,
 * and attempt threshold checks for terugbelverzoeken.
 *
 * @spec openspec/changes/callback-management/tasks.md#1.1
 */
class CallbackService
{
    /**
     * Maximum unsuccessful attempts before suggesting closure.
     *
     * @var int
     */
    public const ATTEMPT_THRESHOLD = 3;

    /**
     * Allowed status transitions.
     *
     * Keys are current statuses, values are arrays of allowed target statuses.
     *
     * @var array<string, array<string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'open'           => ['in_behandeling'],
        'in_behandeling' => ['afgerond', 'verlopen'],
        'afgerond'       => ['open'],
        'verlopen'       => ['open'],
    ];

    /**
     * Results considered unsuccessful for threshold counting.
     *
     * @var array<string>
     */
    private const UNSUCCESSFUL_RESULTS = [
        'niet_bereikbaar',
        'niet_bereikt',
        'geen_gehoor',
        'voicemail',
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager The group manager.
     * @param IUserSession    $userSession  The user session.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Add a callback attempt to a task's attempts array.
     *
     * @param array<string, mixed> $taskData The task data array.
     * @param string               $result   The attempt result.
     * @param string               $notes    Optional attempt notes.
     *
     * @return array<string, mixed> The modified task data with the new attempt.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function addAttempt(array $taskData, string $result, string $notes=''): array
    {
        $attempts = $taskData['attempts'] ?? [];

        $user        = $this->userSession->getUser();
        $agentUserId = 'system';
        if ($user !== null) {
            $agentUserId = $user->getUID();
        }

        $attempts[] = [
            'timestamp'   => (new DateTime())->format(DateTime::ATOM),
            'result'      => $result,
            'notes'       => $notes,
            'agentUserId' => $agentUserId,
        ];

        $taskData['attempts'] = $attempts;

        return $taskData;
    }//end addAttempt()

    /**
     * Check if the attempt threshold has been reached for a task.
     *
     * Counts only unsuccessful attempts (niet_bereikbaar, etc.).
     *
     * @param array<string, mixed> $taskData The task data array.
     *
     * @return bool True if the threshold has been reached.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function isAttemptThresholdReached(array $taskData): bool
    {
        $attempts          = $taskData['attempts'] ?? [];
        $unsuccessfulCount = 0;

        foreach ($attempts as $attempt) {
            if (in_array($attempt['result'] ?? '', self::UNSUCCESSFUL_RESULTS, true) === true) {
                $unsuccessfulCount++;
            }
        }

        return $unsuccessfulCount >= self::ATTEMPT_THRESHOLD;
    }//end isAttemptThresholdReached()

    /**
     * Validate whether the current user can claim a group-assigned task.
     *
     * @param array<string, mixed> $taskData The task data array.
     *
     * @return array{eligible: bool, reason: string} Validation result.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function validateClaim(array $taskData): array
    {
        $groupId = $taskData['assigneeGroupId'] ?? null;

        if (empty($groupId) === true) {
            return [
                'eligible' => false,
                'reason'   => 'Task is not assigned to a group',
            ];
        }

        if (empty($taskData['assigneeUserId']) === false) {
            return [
                'eligible' => false,
                'reason'   => 'Task is already claimed by a user',
            ];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [
                'eligible' => false,
                'reason'   => 'No authenticated user',
            ];
        }

        $isMember = $this->groupManager->isInGroup($user->getUID(), $groupId);

        if ($isMember === false) {
            return [
                'eligible' => false,
                'reason'   => 'User is not a member of the assigned group',
            ];
        }

        return [
            'eligible' => true,
            'reason'   => '',
        ];
    }//end validateClaim()

    /**
     * Validate whether a status transition is allowed.
     *
     * @param string $currentStatus The current task status.
     * @param string $targetStatus  The target task status.
     *
     * @return array{valid: bool, reason: string} Validation result.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function validateStatusTransition(string $currentStatus, string $targetStatus): array
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (in_array($targetStatus, $allowed, true) === false) {
            return [
                'valid'  => false,
                'reason' => "Transition from '{$currentStatus}' to '{$targetStatus}' is not allowed",
            ];
        }

        return [
            'valid'  => true,
            'reason' => '',
        ];
    }//end validateStatusTransition()

    /**
     * Apply a claim to a task: set the current user as assignee and update status.
     *
     * @param array<string, mixed> $taskData The task data array.
     *
     * @return array<string, mixed> The modified task data.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function applyClaim(array $taskData): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $taskData;
        }

        $taskData['assigneeUserId']  = $user->getUID();
        $taskData['assigneeGroupId'] = null;
        $taskData['status']          = 'in_behandeling';

        return $taskData;
    }//end applyClaim()

    /**
     * Apply completion to a task: set status, completedAt, and resultText.
     *
     * @param array<string, mixed> $taskData   The task data array.
     * @param string               $resultText The completion result text.
     *
     * @return array<string, mixed> The modified task data.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function applyCompletion(array $taskData, string $resultText): array
    {
        $taskData['status']      = 'afgerond';
        $taskData['completedAt'] = (new DateTime())->format(DateTime::ATOM);
        $taskData['resultText']  = $resultText;

        return $taskData;
    }//end applyCompletion()

    /**
     * Apply reassignment to a task.
     *
     * @param array<string, mixed> $taskData     The task data array.
     * @param string               $assignee     The new assignee ID.
     * @param string               $assigneeType The assignee type (user or group).
     *
     * @return array<string, mixed> The modified task data.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function applyReassignment(array $taskData, string $assignee, string $assigneeType): array
    {
        if ($assigneeType === 'user') {
            $taskData['assigneeUserId']  = $assignee;
            $taskData['assigneeGroupId'] = null;

            return $taskData;
        }

        $taskData['assigneeGroupId'] = $assignee;
        $taskData['assigneeUserId']  = null;

        return $taskData;
    }//end applyReassignment()

    /**
     * Authorize whether the current user can act on a task.
     *
     * Checks if the user is the assigned agent, member of the assigned group, or a Nextcloud admin.
     *
     * @param array<string, mixed> $taskData The task data array.
     *
     * @return array{authorized: bool, reason: string} Authorization result.
     *
     * @spec openspec/changes/callback-management/tasks.md#1.1
     */
    public function authorizeTaskAccess(array $taskData): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [
                'authorized' => false,
                'reason'     => 'No authenticated user',
            ];
        }

        $userId = $user->getUID();

        // Admin users are always authorized.
        if ($this->groupManager->isAdmin($userId) === true) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        // Check if user is the assigned agent.
        $assignedUserId = $taskData['assigneeUserId'] ?? null;
        if ($assignedUserId === $userId) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        // Check if user is member of the assigned group.
        $assignedGroupId = $taskData['assigneeGroupId'] ?? null;
        if (empty($assignedGroupId) === false && $this->groupManager->isInGroup($userId, $assignedGroupId) === true) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        return [
            'authorized' => false,
            'reason'     => 'User is not authorized to act on this task',
        ];
    }//end authorizeTaskAccess()
}//end class
