<?php

/**
 * Pipelinq QuoteNumberCreatingListener.
 *
 * Gives every new quote its number ("OFF-2026-0042") before it is stored.
 * A quote that arrives with a number keeps it.
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
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\QuoteNumberSequence;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Throwable;

/**
 * Stamps the quote number on a quote that is being created.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */
class QuoteNumberCreatingListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig          $appConfig App config, source of the quote schema id.
	 * @param QuoteNumberSequence $sequence  Allocates the number; it logs every failed attempt.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly QuoteNumberSequence $sequence,
	) {
	}//end __construct()

	/**
	 * Handle an object-creating event: a quote without a number gets the next one.
	 *
	 * A number that cannot be allocated never blocks the save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false || $this->needsNumber(event: $event) === false) {
			return;
		}

		$number = $this->allocate();
		if ($number !== null) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['number' => $number]));
		}
	}//end handle()

	/**
	 * Whether the object being created is a quote without a number.
	 *
	 * @param ObjectCreatingEvent $event The event.
	 *
	 * @return bool True when the quote needs a number.
	 */
	private function needsNumber(ObjectCreatingEvent $event): bool {
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'salesQuote_schema', '');
		$entity = $event->getObject();
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return false;
		}

		$quote = array_merge($entity->getObject(), $event->getModifiedData());
		return trim((string)($quote['number'] ?? '')) === '';
	}//end needsNumber()

	/**
	 * The next quote number, or null when none can be allocated.
	 *
	 * @return string|null The number.
	 */
	private function allocate(): ?string {
		try {
			return $this->sequence->next();
		} catch (Throwable) {
			return null;
		}
	}//end allocate()
}//end class
