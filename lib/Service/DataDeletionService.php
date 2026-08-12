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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires only the OR container
 *  and a logger.
 *
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary
 */
class DataDeletionService {
	/**
	 * Fully-qualified name of OR's consumable data-subject-request service.
	 *
	 * @var string
	 */
	public const OR_REQUEST_SERVICE = 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService';

	/**
	 * OR erase mode: field-level pseudonymise (row retained, PII overwritten).
	 *
	 * Mirrors DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE. Pipelinq always
	 * erases in this mode so the underlying record survives — this is what keeps
	 * the NL 7-year Boekhoudplicht booking retention intact while the PII is
	 * removed.
	 *
	 * @var string
	 */
	public const ERASE_MODE_PSEUDONYMISE = 'pseudonymise';

	/**
	 * Constructor.
	 *
	 * OR's `DataSubjectRequestService` is resolved lazily through the DI
	 * container (the same OR-absent-safe pattern the AVG repository uses for
	 * `ObjectService`) so the app still loads when OR is absent; the erase verb
	 * degrades to a safe empty result in that case rather than throwing.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR resolve).
	 * @param LoggerInterface $logger The logger.
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
	 * @param bool $dryRun When true, report matches/holds without mutating.
	 *
	 * @return array<string, int> Summary {bookings: <erased>, held: <held>} for logging/audit.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary
	 */
	public function pseudonymizeCustomerBookings(string $customerId, bool $dryRun = false): array {
		$summary = ['bookings' => 0, 'held' => 0];

		if ($customerId === '') {
			return $summary;
		}

		$result = $this->eraseViaOr(subjectId: $customerId, type: null, dryRun: $dryRun);

		$summary['bookings'] = count((array)($result['erased'] ?? []));
		$summary['held'] = count((array)($result['held'] ?? []));

		$this->logger->info(
			'Pipelinq: AVG booking erasure via OR completed',
			[
				'summary' => $summary,
				'dryRun' => $dryRun,
				'complete' => (bool)($result['complete'] ?? false),
				'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			]
		);

		return $summary;
	}//end pseudonymizeCustomerBookings()

	/**
	 * Erase a subject's data via OR's legal-hold-aware field-level pseudonymise.
	 *
	 * Resolves OR's `DataSubjectRequestService` lazily and always uses the
	 * PSEUDONYMISE mode so the owning records are RETAINED (the row survives,
	 * matching PII values become OR's `[erased]` token). Objects under an active
	 * legal hold or in an immutable archival status are reported back in the
	 * `held` bucket and never mutated — this preserves the NL Boekhoudplicht
	 * 7-year booking retention. When OR is absent, or the call throws, this
	 * degrades to a safe empty summary rather than throwing.
	 *
	 * @param string $subjectId The subject identifier value.
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param bool $dryRun When true, report matches/holds without mutating.
	 *
	 * @return array<string, mixed> OR's erase summary (erased/held/failed buckets).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
	 */
	private function eraseViaOr(string $subjectId, ?string $type = null, bool $dryRun = false): array {
		$service = $this->requestService();
		if ($service === null) {
			return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
		}

		try {
			return $service->erase(
				subjectId: $subjectId,
				type: $type,
				eraseMode: self::ERASE_MODE_PSEUDONYMISE,
				dryRun: $dryRun
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq AVG: OR erase failed',
				['exception' => $e->getMessage()]
			);
			return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
		}
	}//end eraseViaOr()

	/**
	 * Resolve OR's data-subject-request service, or null when unavailable.
	 *
	 * @return object|null The DataSubjectRequestService, or null.
	 */
	private function requestService(): ?object {
		try {
			return $this->container->get(self::OR_REQUEST_SERVICE);
		} catch (\Throwable $e) {
			return null;
		}
	}//end requestService()
}//end class
