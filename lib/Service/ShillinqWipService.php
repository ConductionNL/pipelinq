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

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;
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
	 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
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

		$payload = $this->buildPayload(timeEntry: $timeEntry, approvedBy: $approvedBy, approvedAt: $approvedAt);

		return $this->dispatch(eventName: self::EVENT_TIME_APPROVED, payload: $payload);
	}//end dispatchWipEvent()

	/**
	 * Build the CloudEvents 1.0 envelope for a WIP event.
	 *
	 * @param array<string, mixed> $timeEntry The time entry data.
	 * @param string $approvedBy The approver's user id.
	 * @param string $approvedAt The ISO 8601 UTC approval timestamp.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
	 */
	private function buildPayload(array $timeEntry, string $approvedBy, string $approvedAt): array {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$time = $approvedAt;
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
			'data' => [
				'timeEntryId' => $uuid,
				'hours' => (float)($timeEntry['hours'] ?? 0),
				'billingCategoryId' => (string)($timeEntry['billingCategory'] ?? ''),
				'clientId' => (string)($timeEntry['client'] ?? ''),
				'leadId' => (string)($timeEntry['lead'] ?? ''),
				'projectId' => (string)($timeEntry['project'] ?? ''),
				'approvedBy' => $approvedBy,
				'approvedAt' => $approvedAt,
			],
		];
	}//end buildPayload()

	/**
	 * Dispatch a CloudEvent through OpenRegister's WebhookService.
	 *
	 * Fire-and-forget: any failure to resolve or invoke the WebhookService is
	 * logged and reported as a false return so the caller can mark the entry
	 * sync as failed, but never throws.
	 *
	 * @param string $eventName The webhook event name.
	 * @param array<string, mixed> $payload The CloudEvent payload.
	 *
	 * @return bool True on successful dispatch, false on failure.
	 */
	private function dispatch(string $eventName, array $payload): bool {
		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: Shillinq WIP CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}//end try
	}//end dispatch()

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
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()
}//end class
