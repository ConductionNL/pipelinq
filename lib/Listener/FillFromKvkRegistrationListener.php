<?php

/**
 * Pipelinq FillFromKvkRegistrationListener.
 *
 * Registers the "Fill in from KvK" bulk action with OpenRegister when it asks
 * the apps for their bulk actions (clients-registry-numbers-and-enrichment
 * design D4).
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\Pipelinq\BulkAction\FillFromKvkAction;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands OpenRegister the Fill in from KvK action.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
 */
class FillFromKvkRegistrationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FillFromKvkAction $action The action.
	 */
	public function __construct(
		private readonly FillFromKvkAction $action,
	) {
	}//end __construct()

	/**
	 * Register the action on OpenRegister's registration event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof BulkActionRegistrationEvent) {
			$event->registerAction(action: $this->action);
		}
	}//end handle()
}//end class
