<?php

/**
 * Pipelinq ShillinqApService.
 *
 * Maps approved pipelinq expense payloads to the Shillinq AP CloudEvents
 * envelope and dispatches them, one-way, through OpenRegister's
 * WebhookService. Delivery is fire-and-forget: a missing consumer or an
 * unavailable OpenRegister must never fail the originating expense write.
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches Shillinq AP voucher CloudEvents.
 *
 * The detected Shillinq app is the integration toggle: without it dispatch is
 * off ({@see self::shouldDispatch()}). With it, expense approvals are emitted
 * as CloudEvents 1.0 envelopes through OpenRegister's WebhookService.
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
class ShillinqApService {
	/**
	 * CloudEvents type for an approved expense.
	 *
	 * @var string
	 */
	public const EVENT_EXPENSE_APPROVED = 'nl.conduction.pipelinq.expense.approved';

	/**
	 * CloudEvents source for AP events.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = '/apps/pipelinq/expenses';

	/**
	 * Constructor.
	 *
	 * @param IntegrationDetector $integrations Detects the Shillinq app on this server.
	 * @param ContainerInterface $container The DI container (OpenRegister WebhookService lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IntegrationDetector $integrations,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether AP dispatch is enabled.
	 *
	 * True when the Shillinq app is installed on this server. Nobody types a
	 * webhook URL: the event travels internally through OpenRegister's
	 * WebhookService, so detecting Shillinq is all the configuration there is.
	 * Without Shillinq the listeners no-op silently.
	 *
	 * @return bool True when Shillinq is installed.
	 *
	 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
	 */
	public function shouldDispatch(): bool {
		return $this->integrations->shillinq()['installed'] === true;
	}//end shouldDispatch()

	/**
	 * Dispatch an expense approval event to the Shillinq AP webhook.
	 *
	 * @param array<string, mixed> $expense The expense object data (must contain uuid + amount).
	 * @param string $approvedBy The approving user id.
	 * @param string $approvedAt The ISO 8601 approval timestamp.
	 *
	 * @return bool True on successful dispatch, false on failure or when unconfigured.
	 *
	 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
	 */
	public function dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool {
		if ($this->shouldDispatch() === false) {
			return false;
		}

		$payload = $this->buildApPayload(expense: $expense, approval: new ShillinqApproval(approvedBy: $approvedBy, approvedAt: $approvedAt));
		return $this->cloudEvents()->dispatch(cloudEvent: $payload, handOff: 'AP');
	}//end dispatchApEvent()

	/**
	 * Build the CloudEvents 1.0 envelope for an approved expense.
	 *
	 * The payload shape matches REQ-AP-003 Scenario 7 exactly so Shillinq
	 * consumers can be conformance-tested independently of pipelinq.
	 *
	 * @param array<string, mixed> $expense  The expense object data.
	 * @param ShillinqApproval     $approval Who approved it, and when.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 */
	private function buildApPayload(array $expense, ShillinqApproval $approval): array {
		$expenseId = (string)($expense['uuid'] ?? $expense['id'] ?? '');
		$time = $this->now();
		if ($approval->approvedAt !== '') {
			$time = $approval->approvedAt;
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_EXPENSE_APPROVED,
			'source' => self::EVENT_SOURCE,
			'id' => $expenseId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => $this->expenseData(expense: $expense, approval: $approval),
		];
	}//end buildApPayload()

	/**
	 * The `data` of an expense-approved CloudEvent.
	 *
	 * @param array<string, mixed> $expense  The expense object data.
	 * @param ShillinqApproval     $approval Who approved it, and when.
	 *
	 * @return array<string, mixed> The event data.
	 */
	private function expenseData(array $expense, ShillinqApproval $approval): array {
		$projectId = null;
		if (isset($expense['project']) === true && $expense['project'] !== '') {
			$projectId = (string)$expense['project'];
		}

		return [
			'expenseId' => (string)($expense['uuid'] ?? $expense['id'] ?? ''),
			'amount' => (float)($expense['amount'] ?? 0),
			'currency' => (string)($expense['currency'] ?? 'EUR'),
			'categoryId' => (string)($expense['category'] ?? ''),
			'clientId' => (string)($expense['client'] ?? ''),
			'projectId' => $projectId,
			'billable' => (bool)($expense['billable'] ?? false),
			'approvedBy' => $approval->approvedBy,
			'approvedAt' => $approval->approvedAt,
		];
	}//end expenseData()

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * Public so listeners and the retry controller can stamp apSyncedAt
	 * consistently without each coupling directly to the date-time classes.
	 *
	 * @return string The ISO 8601 timestamp.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function now(): string {
		return $this->cloudEvents()->now();
	}//end now()

	/**
	 * The shared Shillinq CloudEvent delivery on this service's container and logger.
	 *
	 * @return ShillinqCloudEvents The delivery.
	 */
	private function cloudEvents(): ShillinqCloudEvents {
		return new ShillinqCloudEvents(container: $this->container, logger: $this->logger);
	}//end cloudEvents()
}//end class
