<?php

/**
 * Pipelinq LineTotalsListener.
 *
 * After a quote or lead line is created, changed or deleted, the quote totals or the lead value follow.
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
 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
 */

declare(strict_types=1);
namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Service\LineTotals\LineRollUp;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands every saved or deleted object to the line roll-up, which ignores all but quote and lead lines.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
 */
class LineTotalsListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LineRollUp      $rollUp Writes the parent's totals.
	 * @param LoggerInterface $logger Logs a roll-up that failed.
	 *
	 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
	 */
	public function __construct(
		private readonly LineRollUp $rollUp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Roll the line's totals up into its quote or lead.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
	 */
	public function handle(Event $event): void {
		$line = $this->objectOf(event: $event);
		if ($line === null) {
			return;
		}

		try {
			$this->rollUp->apply(line: $line);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: could not update the totals of a quote or lead', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The object an event is about, as saved.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntityInterface|null The object, or null for another event.
	 */
	private function objectOf(Event $event): ?ObjectEntityInterface {
		if ($event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectDeletedEvent) {
			return $event->getObject();
		}

		return null;
	}//end objectOf()
}//end class
