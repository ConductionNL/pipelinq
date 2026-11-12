<?php

/**
 * Pipelinq ShillinqCloudEvents.
 *
 * Dispatches the CloudEvents pipelinq hands to Shillinq through OpenRegister's WebhookService.
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
 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one-way, fire-and-forget delivery shared by the Shillinq AP and WIP hand-offs.
 *
 * Built by the service that owns the container and logger.
 *
 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
final class ShillinqCloudEvents {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister WebhookService lookup).
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch a CloudEvent, named by its `type`, through OpenRegister's WebhookService.
	 *
	 * Fire-and-forget: any failure to resolve or invoke the WebhookService is
	 * logged and reported as a false return so the caller can mark the sync as
	 * failed, but never throws.
	 *
	 * @param array<string, mixed> $cloudEvent The CloudEvent payload.
	 * @param string               $handOff    The hand-off named in the warning (AP, WIP).
	 *
	 * @return bool True on successful dispatch, false on failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
	 */
	public function dispatch(array $cloudEvent, string $handOff): bool {
		$eventName = (string)$cloudEvent['type'];
		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $cloudEvent
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: Shillinq ' . $handOff . ' CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}//end try
	}//end dispatch()

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * @return string The ISO 8601 timestamp.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()
}//end class
