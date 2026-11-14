<?php

/**
 * Pipelinq ShillinqWipService.
 *
 * Maps approved pipelinq time entries to Shillinq Work-In-Progress (WIP)
 * CloudEvents and dispatches them, one-way, through OpenRegister's
 * WebhookService. Delivery is fire-and-forget: a missing consumer or an
 * unavailable OpenRegister must never fail the originating approval write.
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
 * @spec openspec/changes/archive/2026-06-14-pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches Shillinq WIP CloudEvents.
 *
 * The detected Shillinq app is the integration toggle: without it dispatch is
 * off ({@see self::shouldDispatch()}). With it, approved time entries are
 * emitted as CloudEvents 1.0 envelopes through OpenRegister's WebhookService.
 *
 * @spec openspec/changes/archive/2026-06-14-pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */
class ShillinqWipService {
	/**
	 * CloudEvents type for an approved time entry routed to WIP.
	 *
	 * @var string
	 */
	public const EVENT_TIME_APPROVED = 'nl.conduction.pipelinq.time.approved';

	/**
	 * CloudEvents source for WIP events.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = '/apps/pipelinq/time-entries';

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
	 * Whether WIP dispatch is enabled.
	 *
	 * True when the Shillinq app is installed on this server. Nobody types a
	 * webhook URL: the event travels internally through OpenRegister's
	 * WebhookService, so detecting Shillinq is all the configuration there is.
	 * Without Shillinq the listeners no-op silently.
	 *
	 * @return bool True when Shillinq is installed.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
	 */
	public function shouldDispatch(): bool {
		return $this->integrations->shillinq()['installed'] === true;
	}//end shouldDispatch()

	/**
	 * Dispatch a WIP CloudEvent for an approved time entry.
	 *
	 * Returns true when delivery succeeds, false on any error or when dispatch
	 * is disabled. Never throws — the approval path must complete regardless.
	 *
	 * @param array<string, mixed> $timeEntry The approved time entry data.
	 * @param string $approvedBy The approver's user id.
	 * @param string $approvedAt The ISO 8601 UTC approval timestamp.
	 *
	 * @return bool True on successful dispatch, false on failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
	 */
	public function dispatchWipEvent(array $timeEntry, string $approvedBy, string $approvedAt): bool {
		if ($this->shouldDispatch() === false) {
			return false;
		}

		$payload = $this->buildPayload(timeEntry: $timeEntry, approval: new ShillinqApproval(approvedBy: $approvedBy, approvedAt: $approvedAt));
		return $this->cloudEvents()->dispatch(cloudEvent: $payload, handOff: 'WIP');
	}//end dispatchWipEvent()

	/**
	 * Build the CloudEvents 1.0 envelope for a WIP event.
	 *
	 * @param array<string, mixed> $timeEntry The time entry data.
	 * @param ShillinqApproval     $approval  Who approved it, and when.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
	 */
	private function buildPayload(array $timeEntry, ShillinqApproval $approval): array {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$time = $approval->approvedAt;
		if ($time === '') {
			$time = $this->now();
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_TIME_APPROVED,
			'source' => self::EVENT_SOURCE,
			'id' => $uuid,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => $this->timeEntryData(timeEntry: $timeEntry, approval: $approval),
		];
	}//end buildPayload()

	/**
	 * The `data` of a time-approved CloudEvent.
	 *
	 * @param array<string, mixed> $timeEntry The time entry data.
	 * @param ShillinqApproval     $approval  Who approved it, and when.
	 *
	 * @return array<string, mixed> The event data.
	 */
	private function timeEntryData(array $timeEntry, ShillinqApproval $approval): array {
		return [
			'timeEntryId' => (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? ''),
			'hours' => (float)($timeEntry['hours'] ?? 0),
			'billingCategoryId' => (string)($timeEntry['billingCategory'] ?? ''),
			'clientId' => (string)($timeEntry['client'] ?? ''),
			'leadId' => (string)($timeEntry['lead'] ?? ''),
			'projectId' => (string)($timeEntry['project'] ?? ''),
			'approvedBy' => $approval->approvedBy,
			'approvedAt' => $approval->approvedAt,
		];
	}//end timeEntryData()

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * Public so the time-entry listener can stamp wipSyncedAt consistently
	 * without coupling directly to the date-time classes.
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
