<?php

/**
 * Pipelinq TaskService.
 *
 * Service for task and callback request management.
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
 * @spec openspec/specs/task-background-jobs/spec.md#requirement-task-expiry-background-job
 * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for task and callback request operations.
 *
 * Handles deadline calculation, task validation, and business hours logic.
 */
class TaskService
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
     * Valid task statuses.
     *
     * @var array<string>
     */
    public const VALID_STATUSES = [
        'open',
        'in_behandeling',
        'afgerond',
        'verlopen',
    ];

    /**
     * Valid priority levels.
     *
     * @var array<string>
     */
    public const VALID_PRIORITIES = [
        'hoog',
        'normaal',
        'laag',
    ];

    /**
     * Default business hours start when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_BUSINESS_HOUR_START = 8;

    /**
     * Default business hours end when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_BUSINESS_HOUR_END = 17;

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession The user session.
     * @param IAppConfig      $appConfig   The app config.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        private IUserSession $userSession,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the admin-configured business-hour start (default 8).
     *
     * Tunable via `pipelinq.task.business_hour_start`; the default preserves
     * the historical 08:00 start so an unconfigured install is unchanged.
     *
     * @return int The business-hour start (0-23).
     */
    private function getBusinessHourStart(): int
    {
        return $this->appConfig->getValueInt(
            Application::APP_ID,
            'task.business_hour_start',
            self::DEFAULT_BUSINESS_HOUR_START
        );
    }//end getBusinessHourStart()

    /**
     * Get the admin-configured business-hour end (default 17).
     *
     * Tunable via `pipelinq.task.business_hour_end`; the default preserves the
     * historical 17:00 end so an unconfigured install is unchanged.
     *
     * @return int The business-hour end (0-23).
     */
    private function getBusinessHourEnd(): int
    {
        return $this->appConfig->getValueInt(
            Application::APP_ID,
            'task.business_hour_end',
            self::DEFAULT_BUSINESS_HOUR_END
        );
    }//end getBusinessHourEnd()

    /**
     * Calculate the default deadline (next business day at 17:00).
     *
     * @return string ISO 8601 datetime string.
     * @spec   openspec/changes/reverse-2026-05-26-be-tasks/tasks.md#task-2
     */
    public function getDefaultDeadline(): string
    {
        $now      = new \DateTime();
        $deadline = clone $now;

        // Move to next business day.
        $deadline->modify('+1 day');
        while ($this->isWeekend(date: $deadline) === true) {
            $deadline->modify('+1 day');
        }

        $deadline->setTime($this->getBusinessHourEnd(), 0, 0);

        return $deadline->format(\DateTime::ATOM);
    }//end getDefaultDeadline()

    /**
     * Calculate a deadline respecting business hours.
     *
     * Skips weekends. For example, a 24-hour deadline created Friday at 16:00
     * results in Monday at 16:00.
     *
     * @param string $createdAt     ISO 8601 creation timestamp.
     * @param int    $businessHours Number of business hours to add.
     *
     * @return string ISO 8601 deadline datetime string.
     * @spec   openspec/changes/reverse-2026-05-26-be-tasks/tasks.md#task-1
     */
    public function calculateDeadline(string $createdAt, int $businessHours): string
    {
        $start         = new \DateTime($createdAt);
        $remaining     = $businessHours;
        $businessStart = $this->getBusinessHourStart();
        $businessEnd   = $this->getBusinessHourEnd();

        while ($remaining > 0) {
            $start->modify('+1 hour');

            if ($this->isWeekend(date: $start) === true) {
                continue;
            }

            $hour = (int) $start->format('G');
            if ($hour >= $businessStart && $hour < $businessEnd) {
                $remaining--;
            }
        }

        return $start->format(\DateTime::ATOM);
    }//end calculateDeadline()

    /**
     * Check if a date is on a weekend.
     *
     * @param \DateTime $date The date to check.
     *
     * @return bool True if the date is Saturday or Sunday.
     */
    private function isWeekend(\DateTime $date): bool
    {
        $dayOfWeek = (int) $date->format('N');

        return $dayOfWeek >= 6;
    }//end isWeekend()
}//end class
