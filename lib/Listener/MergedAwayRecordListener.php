<?php

/**
 * Pipelinq MergedAwayRecordListener.
 *
 * After OpenRegister merges a client or contact into another record, the
 * merged-away record says where it went; after a reversal it no longer does.
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
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Kernel\Contracts\MergedAwayStampInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps every merged-away record of a merge, and clears the stamp on a reversal.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */
class MergedAwayRecordListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param MergedAwayStampInterface $stamp  Writes and clears the stamp.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly MergedAwayStampInterface $stamp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a merge event. A failure is logged and never reaches the merge.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectsMergedEvent) {
			$this->stampAll(event: $event);
		}
	}//end handle()

	/**
	 * Stamp every merged-away record of the merge.
	 *
	 * @param ObjectsMergedEvent $event The merge.
	 *
	 * @return void
	 */
	private function stampAll(ObjectsMergedEvent $event): void {
		foreach ($event->getMergedFromUuids() as $uuid) {
			$this->stampOne(uuid: (string)$uuid, event: $event);
		}
	}//end stampAll()

	/**
	 * Stamp one merged-away record.
	 *
	 * @param string             $uuid  The record.
	 * @param ObjectsMergedEvent $event The merge.
	 *
	 * @return void
	 */
	private function stampOne(string $uuid, ObjectsMergedEvent $event): void {
		try {
			$this->stamp->apply(uuid: $uuid, event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: could not mark a merged-away record', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
		}
	}//end stampOne()
}//end class
