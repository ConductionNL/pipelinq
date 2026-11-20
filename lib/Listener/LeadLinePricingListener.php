<?php

/**
 * Pipelinq LeadLinePricingListener.
 *
 * Before a lead's product line is stored, the server writes its price.
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
 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\LinePricing\LeadLinePricer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Prices a lead line from the client's price lists before it is written.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */
class LeadLinePricingListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LeadLinePricer $pricer    Decides the line's price.
	 * @param IAppConfig     $appConfig Holds the lead line schema id.
	 *
	 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
	 */
	public function __construct(
		private readonly LeadLinePricer $pricer,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Merge the line's price into what will be stored.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent) {
			$this->priceLine(event: $event, entity: $event->getNewObject(), old: $event->getOldObject()?->getObject());
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$this->priceLine(event: $event, entity: $event->getObject(), old: null);
		}
	}//end handle()

	/**
	 * Price a lead line that is being created or updated.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param ObjectEntity                            $entity The line being written.
	 * @param array<string, mixed>|null               $old    The line before the update, null on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	private function priceLine(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity, ?array $old): void {
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'leadProduct_schema', '');
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return;
		}

		$line    = array_merge((array)$entity->getObject(), $event->getModifiedData());
		$figures = $this->pricer->figures(line: $line, old: $old);
		if ($figures !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $figures));
		}
	}//end priceLine()
}//end class
