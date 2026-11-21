<?php

/**
 * Pipelinq ClientIdentifierFormatGuard.
 *
 * Refuses a client save whose identifiers do not match their schemes' formats
 * (clients-registry-numbers-and-enrichment, design D2: format on every save).
 * The schema holds the patterns JSON schema can express; this guard adds what
 * it cannot, the RSIN eleven test and the per-country VAT pattern, on every
 * write path that reaches OpenRegister: the form, the API, imports and bulk
 * jobs alike.
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

use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Refuses a client whose identifiers are malformed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md#requirement-an-organisation-client-holds-its-official-numbers-req-crn-001
 */
class ClientIdentifierFormatGuard implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ClientIdentifierServiceInterface $identifiers The format rules.
	 * @param IAppConfig                       $appConfig   The client schema id.
	 */
	public function __construct(
		private readonly ClientIdentifierServiceInterface $identifiers,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Refuse the write when the client's identifiers are malformed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->refuseWhenMalformed(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->refuseWhenMalformed(event: $event, object: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Set the refusal on the event when the client's identifiers have a problem.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param ObjectEntityInterface                   $object The object being written.
	 *
	 * @return void
	 */
	private function refuseWhenMalformed(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntityInterface $object): void {
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		if ($schema === '' || (string)$object->getSchema() !== $schema) {
			return;
		}

		$data = array_merge($object->getObject(), $event->getModifiedData());
		if (array_key_exists('identifiers', $data) === false) {
			return;
		}

		$problems = $this->identifiers->problemsIn(identifiers: $data['identifiers']);
		if ($problems === []) {
			return;
		}

		$event->setErrors(['message' => implode(' ', $problems), 'identifiers' => $problems]);
		$event->stopPropagation();
	}//end refuseWhenMalformed()
}//end class
