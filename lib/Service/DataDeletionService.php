<?php

/**
 * Pipelinq DataDeletionService.
 *
 * AVG / GDPR right-to-be-forgotten for the appointment-booking module. Calls
 * OpenRegister's canonical, legal-hold-aware erasure
 * (`DataSubjectRequestService::erase` in `pseudonymise` mode) DIRECTLY — the
 * former app-side `OrGdprBridge` adapter was removed by consume-or-dsar
 * (ADR-047 Phase 3), so the OR service is now resolved lazily through the
 * container (OR-absent safe). This replaces the earlier divergent named-field
 * SHA-256 hashing of `customerName` / `customerEmail` / `customerPhone`.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * AVG right-to-be-forgotten erasure for Bookings, via OR's canonical capability.
 *
 * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary
 */
class DataDeletionService
{
    /**
     * OpenRegister's data-subject erasure service (resolved lazily).
     *
     * @var string
     */
    private const OR_REQUEST_SERVICE = 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazy OR service resolve).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
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
     * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary
     */
    public function pseudonymizeCustomerBookings(string $customerId, bool $dryRun=false): array
    {
        $summary = ['bookings' => 0, 'held' => 0];

        if ($customerId === '') {
            return $summary;
        }

        $service = $this->resolveOrService();
        if ($service === null) {
            $this->logger->warning('Pipelinq: AVG booking erasure skipped — OpenRegister DSAR service unavailable.');
            return $summary;
        }

        $result = $service->erase(subjectId: $customerId, type: null, dryRun: $dryRun);

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

    /**
     * Lazily resolve OpenRegister's DataSubjectRequestService, or null when
     * OpenRegister is absent.
     *
     * @return object|null The OR erasure service, or null.
     */
    private function resolveOrService(): ?object
    {
        try {
            return $this->container->get(self::OR_REQUEST_SERVICE);
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveOrService()
}//end class
