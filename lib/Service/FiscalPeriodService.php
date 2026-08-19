<?php

/**
 * Pipelinq FiscalPeriodService.
 *
 * Resolves the currently-open fiscal period, days remaining and closed state.
 * Falls back to calendar quarters when no custom fiscal calendar is configured.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Calendar-quarter fiscal period helper.
 *
 * Period ids have the form "Q<n>-<year>" (e.g. Q2-2026). All computation is
 * pure and deterministic for a supplied reference date so it is unit-testable.
 */
class FiscalPeriodService
{
    /**
     * Resolve the period id for a reference date (calendar quarter).
     *
     * @param DateTimeInterface $date The reference date.
     *
     * @return string The period id, e.g. "Q2-2026".
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-02
     */
    public function periodIdFor(DateTimeInterface $date): string
    {
        $month   = (int) $date->format('n');
        $quarter = (int) ceil($month / 3);
        $year    = (int) $date->format('Y');

        return sprintf('Q%d-%d', $quarter, $year);
    }//end periodIdFor()

    /**
     * Resolve the currently-open period id for "now".
     *
     * @param DateTimeInterface|null $now The reference time (defaults to now).
     *
     * @return string The current period id.
     */
    public function currentPeriodId(?DateTimeInterface $now=null): string
    {
        return $this->periodIdFor(date: $now ?? new DateTimeImmutable());
    }//end currentPeriodId()

    /**
     * The last calendar day of a period.
     *
     * @param string $periodId The period id (Q<n>-<year>).
     *
     * @return DateTimeImmutable|null The period end date, or null when malformed.
     *
     * @spec exclude mechanical phpmd cleanup — no behaviour change
     */
    public function periodEnd(string $periodId): ?DateTimeImmutable
    {
        if (preg_match('/^Q([1-4])-(\d{4})$/', $periodId, $matches) !== 1) {
            return null;
        }

        $quarter      = (int) $matches[1];
        $year         = (int) $matches[2];
        $endMonth     = ($quarter * 3);
        $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth));

        return $firstOfMonth->modify('last day of this month')->setTime(23, 59, 59);
    }//end periodEnd()

    /**
     * Days remaining in a period from a reference date (never negative).
     *
     * @param string                 $periodId The period id.
     * @param DateTimeInterface|null $now      The reference time (defaults to now).
     *
     * @return int Whole days remaining (0 once the period has ended).
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008-04
     */
    public function daysRemaining(string $periodId, ?DateTimeInterface $now=null): int
    {
        $end = $this->periodEnd(periodId: $periodId);
        if ($end === null) {
            return 0;
        }

        $reference = $now ?? new DateTimeImmutable();
        $seconds   = $end->getTimestamp() - $reference->getTimestamp();
        if ($seconds <= 0) {
            return 0;
        }

        return (int) floor($seconds / 86400);
    }//end daysRemaining()

    /**
     * Whether a period has closed as of a reference date.
     *
     * @param string                 $periodId The period id.
     * @param DateTimeInterface|null $now      The reference time (defaults to now).
     *
     * @return bool True when the period end is in the past.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-007-01
     */
    public function isClosed(string $periodId, ?DateTimeInterface $now=null): bool
    {
        $end = $this->periodEnd(periodId: $periodId);
        if ($end === null) {
            return false;
        }

        $reference = $now ?? new DateTimeImmutable();
        return $reference->getTimestamp() > $end->getTimestamp();
    }//end isClosed()
}//end class
