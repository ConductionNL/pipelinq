<?php

/**
 * Pipelinq ClientRiskChangeListener.
 *
 * Recomputes a client's churn risk the moment one of its contracts changes
 * status or one of its survey answers is classified a detractor.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Design D2: a client does not wait a day to turn red.
 *
 * Listens after the save rather than in the services that write contracts and
 * survey answers, because both are also written through OpenRegister's API
 * and only the save sees every writer. A recompute that fails is logged; the
 * daily job catches it up.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */
class ClientRiskChangeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig        $appConfig App config: the contract and survey answer schemas.
	 * @param ClientRiskService $risk      Scores and stores a client's risk.
	 * @param LoggerInterface   $logger    PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ClientRiskService $risk,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-created or object-updated event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent) {
			$entity = $event->getNewObject();
			$before = ($event->getOldObject()?->getObject() ?? []);
		} else if ($event instanceof ObjectCreatedEvent) {
			$entity = $event->getObject();
			$before = [];
		} else {
			return;
		}

		$after    = $entity->getObject();
		$schema   = (string) $entity->getSchema();
		$clientId = $this->clientOf(schema: $schema, before: $before, after: $after);
		if ($clientId === '') {
			return;
		}

		try {
			$this->risk->recompute(clientId: $clientId);
		} catch (Throwable $e) {
			$this->logger->warning('ClientRiskChangeListener: the client risk was not recomputed', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The client to recompute: a contract's whose status changed, or a survey
	 * answer's that became a detractor; '' for anything else.
	 *
	 * @param string              $schema The saved object's schema id.
	 * @param array<string,mixed> $before The object before the save.
	 * @param array<string,mixed> $after  The object after the save.
	 *
	 * @return string
	 */
	private function clientOf(string $schema, array $before, array $after): string {
		$contractSchema = $this->appConfig->getValueString(Application::APP_ID, 'contract_schema', '');
		$surveySchema   = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');

		$statusChanged = ($before['status'] ?? null) !== ($after['status'] ?? null);
		$becameDetractor = ($after['classification'] ?? null) === 'detractor' && ($before['classification'] ?? null) !== 'detractor';

		if (($schema === $contractSchema && $contractSchema !== '' && $statusChanged === true)
			|| ($schema === $surveySchema && $surveySchema !== '' && $becameDetractor === true)
		) {
			return (string) ($after['clientRef'] ?? '');
		}

		return '';
	}//end clientOf()
}//end class
