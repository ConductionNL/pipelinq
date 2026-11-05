<?php

/**
 * Pipelinq PosLinePricingListener.
 *
 * Before a till line is stored, the server writes its VAT and total.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Service\PosCart\PosLinePricer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Overwrites the figures of a posTransactionLine being created or changed with the server's.
 *
 * A pre-save hook: the figures go into the same write, so no client-sent
 * taxAmount or lineTotal is ever stored.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */
class PosLinePricingListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param PosLinePricer $pricer Prices the line.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function __construct(
		private readonly PosLinePricer $pricer,
	) {
	}//end __construct()

	/**
	 * Price a till line on its way into the store.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($this->pricer->objects->isLineSchema(schemaId: (string)$entity->getSchema()) === false) {
			return;
		}

		$line = array_merge($entity->getObject(), $event->getModifiedData());
		$event->setModifiedData(array_merge($event->getModifiedData(), $this->pricer->figures(line: $line)));
	}//end handle()

	/**
	 * The object being stored.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()
}//end class
