<?php

/**
 * Pipelinq DataDeletionService.
 *
 * AVG / GDPR right-to-be-forgotten for the appointment-booking module. ADOPTS
 * OpenRegister's canonical, legal-hold-aware erasure
 * (`DataSubjectRequestService::erase` in `pseudonymise` mode) instead of the
 * earlier divergent named-field SHA-256 hashing of `customerName` /
 * `customerEmail` / `customerPhone`. This authorized behavioural change is
 * recorded in openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
 *
 * OR's pseudonymise mode is a field-level VALUE overwrite (matching PII becomes
 * the `[erased]` token) followed by a save — it never deletes the owning row,
 * and it skips any object under an active legal hold / immutable archival
 * status. That is exactly what keeps the NL Boekhoudplicht 7-year booking
 * retention intact: the Booking row survives, only its PII is removed, so the
 * accounting aggregates (counts, totals, revenue) stay valid.
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
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\Avg\OrGdprBridge;
use Psr\Log\LoggerInterface;

/**
 * AVG right-to-be-forgotten erasure for Bookings, via OR's canonical capability.
 *
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */
class DataDeletionService
{
    /**
     * Constructor.
     *
     * @param OrGdprBridge    $orGdpr Bridge onto OR's legal-hold-aware erasure.
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private OrGdprBridge $orGdpr,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Erase a customer's identifying data via OR's pseudonymise erasure.
     *
     * Delegates to OpenRegister's `DataSubjectRequestService::erase` in
     * `pseudonymise` mode: OR discovers the customer's objects through its NER
     * index (RBAC + tenant scoped), overwrites the matching PII values in place
     * with the `[erased]` token, and RETAINS every row. Any Booking held by an
     * active legal hold or immutable archival status (NL Boekhoudplicht 7-year
     * retention) is reported back in the `held` bucket and left untouched — the
     * row is never deleted. The customer identifier is never logged in full.
     *
     * @param string $customerId The customer subject identifier whose data to erase.
     * @param bool   $dryRun     When true, report matches/holds without mutating.
     *
     * @return array<string, int> Summary {bookings: <erased>, held: <held>} for logging/audit.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function pseudonymizeCustomerBookings(string $customerId, bool $dryRun=false): array
    {
        $summary = ['bookings' => 0, 'held' => 0];

        if ($customerId === '') {
            return $summary;
        }

        $result = $this->orGdpr->erase(subjectId: $customerId, type: null, dryRun: $dryRun);

        $summary['bookings'] = count((array) ($result['erased'] ?? []));
        $summary['held']     = count((array) ($result['held'] ?? []));

        $this->logger->info(
            'Pipelinq: AVG booking erasure via OR completed',
            [
                'summary'   => $summary,
                'dryRun'    => $dryRun,
                'complete'  => (bool) ($result['complete'] ?? false),
                'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ]
        );

        return $summary;
    }//end pseudonymizeCustomerBookings()
}//end class
