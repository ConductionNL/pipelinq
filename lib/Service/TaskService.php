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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-23
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-24
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

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
     * Default business hours start.
     *
     * @var int
     */
    private const BUSINESS_HOUR_START = 8;

    /**
     * Default business hours end.
     *
     * @var int
     */
    private const BUSINESS_HOUR_END = 17;

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession The user session.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

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

        $deadline->setTime(self::BUSINESS_HOUR_END, 0, 0);

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
        $start     = new \DateTime($createdAt);
        $remaining = $businessHours;

        while ($remaining > 0) {
            $start->modify('+1 hour');

            if ($this->isWeekend(date: $start) === true) {
                continue;
            }

            $hour = (int) $start->format('G');
            if ($hour >= self::BUSINESS_HOUR_START && $hour < self::BUSINESS_HOUR_END) {
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
