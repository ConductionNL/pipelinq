<?php

/**
 * Pipelinq DeadlineService.
 *
 * Server-authoritative legal-deadline logic for AVG requests. The base deadline
 * computation ADOPTS OpenRegister's canonical EU GDPR art-12(3) mechanic via
 * {@see OrGdprBridge}: the response term is ONE MONTH from receipt (not the
 * earlier NL 30-day approximation) and a single extension adds TWO MONTHS (not
 * the earlier 60-day approximation). This authorized behavioural change is
 * recorded in openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
 *
 * The escalation chain that OR does NOT own — the 7-day advance reminder, the
 * <72h team-lead escalation and the breach classification — stays in pipelinq
 * as the Dutch operational overlay, but it is now computed FROM the OR-derived
 * EU deadline.
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
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Deadline computation for the AVG workflow, anchored on OR's EU art-12 maths.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 *  is the idiomatic immutable-date factory; there is no instance alternative.
 *
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */
class DeadlineService
{
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
     * Marker value passed as `extensionDays` to request the EU art-12 extension.
     *
     * The legal extension is "a further two months" (a calendar interval), not a
     * fixed day count, so this is a non-zero sentinel meaning "apply the single
     * permitted extension" rather than a literal number of days.
     *
     * @var int
     */
    public const EXTENSION_DAYS = 1;

    /**
     * Constructor.
     *
     * @param OrGdprBridge $orGdpr Bridge onto OR's canonical EU art-12 deadline maths.
     */
    public function __construct(
        private OrGdprBridge $orGdpr,
    ) {
    }//end __construct()

    /**
     * Compute the legal deadline from an intake timestamp.
     *
     * The base term is ONE MONTH from receipt (OR `DataSubjectDeadline`, EU
     * art-12(3)); when an extension is requested the single permitted TWO-MONTH
     * extension is applied on top of the base. The deadline is normalised to the
     * end of its calendar day so the citizen always has the full final day — the
     * one piece of NL operational presentation kept on top of the OR mechanic.
     *
     * @param DateTimeInterface $submittedAt   The intake timestamp.
     * @param int               $extensionDays Non-zero to apply the single EU extension.
     *
     * @return DateTimeImmutable The legal deadline.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function computeDeadline(DateTimeInterface $submittedAt, int $extensionDays=0): DateTimeImmutable
    {
        $due = $this->orGdpr->computeDueAt(receivedAt: $submittedAt);
        if ($extensionDays > 0) {
            $due = $this->orGdpr->extend(dueAt: $due);
        }

        return $due->setTime(23, 59, 59);
    }//end computeDeadline()

    /**
     * Whole days remaining until the deadline (negative when breached).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return int The number of whole days remaining.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
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
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function hoursRemaining(DateTimeInterface $deadline, DateTimeInterface $now): float
    {
        return (($deadline->getTimestamp() - $now->getTimestamp()) / 3600);
    }//end hoursRemaining()

    /**
     * Classify the urgency colour for a deadline.
     *
     * Red: breached or < ESCALATION_HOURS remaining; yellow: <= REMINDER_DAYS
     * remaining; green otherwise. Operational overlay over the OR deadline.
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return string One of 'red', 'yellow', 'green'.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
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
     * Whether a request should escalate (within ESCALATION_HOURS or breached).
     *
     * @param DateTimeInterface $deadline The legal deadline.
     * @param DateTimeInterface $now      The reference time.
     *
     * @return bool True when the request needs team-lead escalation.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
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
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
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
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
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
