<?php

/**
 * Pipelinq PortalAuditService.
 *
 * Append-only audit logging for every sensitive customer-portal action (login,
 * MFA, profile change, document download, request submission, data export,
 * account closure). Records are written once and never updated or deleted, so a
 * DPO / compliance audit can trust the trail (AVG Art. 15, REQ-007, REQ-010).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Writes immutable portal_audit_event records.
 */
class PortalAuditService {
	/**
	 * Schema slug for audit events.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalAuditEvent';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param ITimeFactory $time The time factory.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Append a single audit event. Never throws: an audit-write failure must
	 * not break the action being audited, but it is logged to the server log
	 * so the gap is visible.
	 *
	 * @param string|null $accountId The acting portal account id.
	 * @param string $tenantId The tenant id.
	 * @param string $eventType The event type (e.g. login-success).
	 * @param string $outcome The outcome (success|denied|error|pending-verification).
	 * @param array<string, mixed> $details Event-specific structured detail.
	 *
	 * @return void
	 */
	public function log(
		?string $accountId,
		string $tenantId,
		string $eventType,
		string $outcome,
		array $details = [],
	): void {
		$record = [
			'accountId' => $accountId,
			'tenantId' => $tenantId,
			'eventType' => $eventType,
			'outcome' => $outcome,
			'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'targetObjectType' => ($details['targetObjectType'] ?? null),
			'targetObjectId' => ($details['targetObjectId'] ?? null),
			'ipHash' => ($details['ipHash'] ?? null),
			'userAgentHash' => ($details['userAgentHash'] ?? null),
		];

		// Keep structured details that are not promoted to first-class columns.
		$extra = $details;
		unset($extra['targetObjectType'], $extra['targetObjectId'], $extra['ipHash'], $extra['userAgentHash']);
		if (empty($extra) === false) {
			$record['details'] = $extra;
		}

		try {
			$this->repository->save(self::SCHEMA, $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: failed to write audit event',
				['eventType' => $eventType, 'exception' => $e->getMessage()]
			);
		}
	}//end log()

	/**
	 * List audit events for a single account (the account's own trail).
	 *
	 * @param string $accountId The account id.
	 *
	 * @return array<int, array<string, mixed>> The events, newest first.
	 */
	public function getForAccount(string $accountId): array {
		$events = $this->repository->findAll(self::SCHEMA, ['accountId' => $accountId]);
		return $this->sortByOccurredAtDesc(events: $events);
	}//end getForAccount()

	/**
	 * List all audit events for a tenant (DPO / admin access).
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<int, array<string, mixed>> The events, newest first.
	 */
	public function getForTenant(string $tenantId): array {
		$events = $this->repository->findAll(self::SCHEMA, ['tenantId' => $tenantId]);
		return $this->sortByOccurredAtDesc(events: $events);
	}//end getForTenant()

	/**
	 * Sort events by occurredAt descending (most recent first).
	 *
	 * @param array<int, array<string, mixed>> $events The events.
	 *
	 * @return array<int, array<string, mixed>> The sorted events.
	 */
	private function sortByOccurredAtDesc(array $events): array {
		usort(
			$events,
			static function (array $left, array $right): int {
				return strcmp((string)($right['occurredAt'] ?? ''), (string)($left['occurredAt'] ?? ''));
			}
		);

		return $events;
	}//end sortByOccurredAtDesc()
}//end class
