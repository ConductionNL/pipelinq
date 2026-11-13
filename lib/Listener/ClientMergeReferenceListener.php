<?php

/**
 * Pipelinq ClientMergeReferenceListener.
 *
 * When OpenRegister merges a client or contact into another record, every
 * pipelinq row that referred to the merged-away record is pointed at the record
 * that stays, and the merged-away record keeps the list of rows it moved. When
 * the merge is reversed, exactly those rows are pointed back.
 *
 * Registered before MergedAwayRecordListener, so a reversal reads the list before
 * the merged-away stamp is cleared.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Merge\PartyReferenceMover;
use OCA\Pipelinq\Service\Merge\PartyReferences;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves client and contact references on a merge and back on its reversal.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */
class ClientMergeReferenceListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects   OpenRegister's object service.
	 * @param PartyReferenceMover    $mover     Moves the references.
	 * @param IAppConfig             $appConfig The client and contact schema ids.
	 * @param LoggerInterface        $logger    Logs a failed move and every skipped row.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly PartyReferenceMover $mover,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a merge or its reversal.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectsMergedEvent) === false) {
			return;
		}

		foreach ($event->getMergedFromUuids() as $fromUuid) {
			try {
				$this->handleOne(event: $event, fromUuid: (string)$fromUuid);
			} catch (Throwable $e) {
				$this->logger->error(
					'Pipelinq merge: the references of a merged record could not be moved',
					['mergedFrom' => $fromUuid, 'survivor' => $event->getSurvivorUuid(), 'reversal' => $event->isReversal(), 'error' => $e->getMessage()]
				);
			}
		}
	}//end handle()

	/**
	 * Move the references of one merged-away record, or move them back.
	 *
	 * @param ObjectsMergedEvent $event    The merge.
	 * @param string             $fromUuid The merged-away record.
	 *
	 * @return void
	 */
	private function handleOne(ObjectsMergedEvent $event, string $fromUuid): void {
		$record = $this->objects->find(id: $fromUuid, _rbac: false, _multitenancy: false, _render: false, _audit: false);
		$partyType = $this->partyType(record: $record);
		if ($record === null || $partyType === '') {
			return;
		}

		$intoUuid = $event->getSurvivorUuid();
		if ($event->isReversal() === false) {
			$moved = $this->mover->move(partyType: $partyType, fromUuid: $fromUuid, intoUuid: $intoUuid);
			$this->objects->patchObject(objectId: $fromUuid, data: [PartyReferences::LEDGER_FIELD => $moved], _rbac: false, _multitenancy: false);
			return;
		}

		$ledger = $record->getObject()[PartyReferences::LEDGER_FIELD] ?? [];
		if (is_array($ledger) === false) {
			$ledger = [];
		}

		$result = $this->mover->restore(ledger: $ledger, fromUuid: $fromUuid, intoUuid: $intoUuid);
		foreach ($result['skipped'] as $skipped) {
			$this->logger->warning(
				'Pipelinq merge: a row changed since the merge was not moved back',
				['mergedFrom' => $fromUuid, 'survivor' => $intoUuid, 'operation' => $event->getMergeOperationId()] + $skipped
			);
		}

		$this->objects->patchObject(objectId: $fromUuid, data: [PartyReferences::LEDGER_FIELD => null], _rbac: false, _multitenancy: false);
	}//end handleOne()

	/**
	 * Whether a record is a client or a contact, by its schema.
	 *
	 * @param ObjectEntityInterface|null $record The record.
	 *
	 * @return string `client`, `contact`, or '' for anything else.
	 */
	private function partyType(?ObjectEntityInterface $record): string {
		if ($record === null) {
			return '';
		}

		$schema = (string)$record->getSchema();
		foreach (['client', 'contact'] as $type) {
			$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, $type.'_schema', '');
			if ($configured !== '' && $configured === $schema) {
				return $type;
			}
		}

		return '';
	}//end partyType()
}//end class
