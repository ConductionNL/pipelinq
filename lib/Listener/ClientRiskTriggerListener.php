<?php

/**
 * Pipelinq ClientRiskTriggerListener.
 *
 * Recomputes a client's churn risk at once when one of its contracts changes
 * status or one of its survey answers is classified as a detractor, so the
 * client does not wait for the nightly job to turn red.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queue a churn risk recompute for the client of a changed contract or a new detractor answer.
 *
 * ADR-078: handle() only compares the old and new object in memory and
 * queues the client id; the recompute runs in {@see DeferredObjectListenerJob}.
 * The recompute reads current state, so a repeated or late entry is harmless.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskTriggerListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'client-risk';

	/**
	 * Constructor.
	 *
	 * @param ClientRiskService $riskService The risk service.
	 * @param IAppConfig $appConfig App config, holding the contract and survey response schema ids.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ClientRiskService $riskService,
		private readonly IAppConfig $appConfig,
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the recompute when a trigger fired. Never throws.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$clientId = $this->triggeredClient(event: $event);
			if ($clientId === '') {
				return;
			}

			$this->deferral->defer(
				jobClass: DeferredObjectListenerJob::class,
				entry: ['handler' => self::HANDLER_KEY, 'uuid' => $clientId],
				dedupeKey: self::HANDLER_KEY . '|' . $clientId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ClientRiskTriggerListener: the risk recompute could not be queued',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Recompute the queued client.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function runDeferredWork(array $entry): void {
		$clientId = (string)($entry['uuid'] ?? '');
		if ($clientId !== '') {
			$this->riskService->recompute(clientId: $clientId);
		}
	}//end runDeferredWork()

	/**
	 * The client whose risk the event changes, or ''.
	 *
	 * A contract counts when its status changed; a survey answer when its
	 * classification became detractor.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return string The client id, or ''.
	 */
	private function triggeredClient(ObjectCreatedEvent|ObjectUpdatedEvent $event): string {
		$entity = $event->getObject();
		$previous = [];
		if ($event instanceof ObjectUpdatedEvent) {
			$entity = $event->getNewObject();
			$old = $event->getOldObject();
			if ($old !== null) {
				$previous = (array)$old->getObject();
			}
		}

		$schemaId = (string)$entity->getSchema();
		$data = (array)$entity->getObject();

		$field = match ($schemaId) {
			$this->schema(key: 'contract_schema') => 'status',
			$this->schema(key: 'surveyResponse_schema') => 'classification',
			default => '',
		};
		if ($field === '' || $schemaId === '') {
			return '';
		}

		$value = (string)($data[$field] ?? '');
		if ($value === '' || $value === (string)($previous[$field] ?? '')) {
			return '';
		}

		if ($field === 'classification' && $value !== 'detractor') {
			return '';
		}

		return trim((string)($data['clientRef'] ?? ''));
	}//end triggeredClient()

	/**
	 * A schema id from app config, or a value no schema id equals.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string
	 */
	private function schema(string $key): string {
		$id = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($id === '') {
			return "\0unset";
		}

		return $id;
	}//end schema()
}//end class
