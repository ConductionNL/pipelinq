<?php

/**
 * Pipelinq TicketPhoneListener.
 *
 * Stores the phone number a ticket is about in E.164 on every save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * One place for every writer: CTI contact moments, logged contact moments and
 * tickets made from a web form all pass through the save, so each is stored
 * with `phoneE164` and the phone search finds it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
class TicketPhoneListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig  The app config.
	 * @param PhoneNormaliser $normaliser The phone normaliser the screen pop uses.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly PhoneNormaliser $normaliser,
	) {
	}//end __construct()

	/**
	 * Add `phoneE164` to a ticket being created or updated.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			return;
		}

		$ticketSchema = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		if ($ticketSchema === '' || (string)$entity->getSchema() !== $ticketSchema) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());
		$e164 = $this->numberOf(ticket: $ticket);
		if ($e164 === null || ($ticket['phoneE164'] ?? null) === $e164) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['phoneE164' => $e164]));
	}//end handle()

	/**
	 * The E.164 number a ticket is about, or null when it carries none.
	 *
	 * An outbound call is about the number called; any other ticket is about
	 * the number that called or the one a resident typed.
	 *
	 * @param array<string, mixed> $ticket The ticket data.
	 *
	 * @return string|null The E.164 number.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function numberOf(array $ticket): ?string {
		$candidates = [$ticket['from_number'] ?? null, $ticket['reporterPhone'] ?? null];
		if (($ticket['direction'] ?? '') === 'outbound') {
			$candidates = [$ticket['to_number'] ?? null, $ticket['reporterPhone'] ?? null];
		}

		foreach ($candidates as $raw) {
			if (is_string($raw) === false || trim($raw) === '') {
				continue;
			}

			$e164 = $this->normaliser->normaliseForOrg($raw)['e164'];
			if ($e164 !== null) {
				return $e164;
			}
		}

		return null;
	}//end numberOf()
}//end class
