<?php

/**
 * Pipelinq DeadlineService.
 *
 * Server-authoritative legal-deadline logic for AVG requests: computing the
 * 30-day statutory deadline (and the 60-day extension), classifying remaining
 * urgency, and detecting escalation / breach states. All date arithmetic lives
 * here so the controllers, jobs and tests share one source of truth.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Pure deadline computation for the AVG workflow.
 *
 * Intentionally dependency-light (no OR / config) so the legal-deadline rules
 * are unit-testable in isolation and deterministic.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 *  is the idiomatic immutable-date factory; there is no instance alternative.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
 */
class DeadlineService
{
    /**
     * Statutory base term in days (AVG art. 12 lid 3).
     *
     * @var int
     */
    public const BASE_TERM_DAYS = 30;

    /**
     * Extension term in days when a justified extension is granted.
     *
     * @var int
     */
    public const EXTENSION_DAYS = 60;

    /**
     * Remaining-hours threshold below which a request escalates to the team lead.
     *
     * @var int
     */
    public const ESCALATION_HOURS = 72;

    /**
     * Days-out at which the 7-day advance reminder is sent.
     *
     * @var int
     */
    public const REMINDER_DAYS = 7;

    /**
     * Compute the legal deadline from an intake timestamp.
     *
     * The deadline is the end of the day BASE_TERM_DAYS after intake (plus the
     * extension when one was granted), so the citizen always has the full final
     * calendar day.
     *
     * @param DateTimeInterface $submittedAt   The intake timestamp.
     * @param int               $extensionDays Extra days granted (0 or EXTENSION_DAYS).
     *
     * @return DateTimeImmutable The legal deadline.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function computeDeadline(DateTimeInterface $submittedAt, int $extensionDays=0): DateTimeImmutable
    {
        $days = self::BASE_TERM_DAYS + max(0, $extensionDays);
        $base = DateTimeImmutable::createFromInterface($submittedAt);

        return $base->add(new DateInterval('P'.$days.'D'))->setTime(23, 59, 59);
    }//end computeDeadline()

    /**
     * Whole days remaining until the deadline (negative when breached).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return int The number of whole days remaining.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function daysRemaining(DateTimeInterface $deadline, DateTimeInterface $now): int
    {
        $diff = ($deadline->getTimestamp() - $now->getTimestamp());

        return (int) floor($diff / 86400);
    }//end daysRemaining()

    /**
     * Hours remaining until the deadline (negative when breached).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return float The number of hours remaining.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function hoursRemaining(DateTimeInterface $deadline, DateTimeInterface $now): float
    {
        return (($deadline->getTimestamp() - $now->getTimestamp()) / 3600);
    }//end hoursRemaining()

    /**
     * Classify the urgency colour for a deadline.
     *
     * Red: breached or < ESCALATION_HOURS remaining; yellow: <= REMINDER_DAYS
     * remaining; green otherwise.
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return string One of 'red', 'yellow', 'green'.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function urgency(DateTimeInterface $deadline, DateTimeInterface $now): string
    {
        $hours = $this->hoursRemaining(deadline: $deadline, now: $now);
        if ($hours < self::ESCALATION_HOURS) {
            return 'red';
        }

        if ($hours <= (self::REMINDER_DAYS * 24)) {
            return 'yellow';
        }

        return 'green';
    }//end urgency()

    /**
     * Whether a request should escalate (within ESCALATION_HOURS and not breached
     * is still escalation; breached is also escalation).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return bool True when the request needs team-lead escalation.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function shouldEscalate(DateTimeInterface $deadline, DateTimeInterface $now): bool
    {
        return ($this->hoursRemaining(deadline: $deadline, now: $now) < self::ESCALATION_HOURS);
    }//end shouldEscalate()

    /**
     * Whether the deadline has been breached.
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return bool True when the deadline is in the past.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function isBreached(DateTimeInterface $deadline, DateTimeInterface $now): bool
    {
        return ($deadline->getTimestamp() < $now->getTimestamp());
    }//end isBreached()

    /**
     * Whether the 7-day advance reminder is due today for a deadline.
     *
     * True when the deadline falls on the calendar day REMINDER_DAYS days from
     * now (so the reminder fires exactly once when the daily job runs).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return bool True when the reminder is due.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function isReminderDue(DateTimeInterface $deadline, DateTimeInterface $now): bool
    {
        $deadlineDay = DateTimeImmutable::createFromInterface($deadline)->setTime(0, 0, 0);
        $target      = DateTimeImmutable::createFromInterface($now)
            ->setTime(0, 0, 0)
            ->add(new DateInterval('P'.self::REMINDER_DAYS.'D'));

        return ($deadlineDay->format('Y-m-d') === $target->format('Y-m-d'));
    }//end isReminderDue()
}//end class
