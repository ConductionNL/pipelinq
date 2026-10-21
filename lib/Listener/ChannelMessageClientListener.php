<?php

/**
 * Pipelinq ChannelMessageClientListener.
 *
 * Stamps a WhatsApp or SMS message with the client its contact belongs to,
 * before it is stored, so a client page lists the messages of all its
 * contacts with one filter.
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
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Throwable;

/**
 * Fills `clientId` on a message from its contact.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-a-message-names-the-client-of-its-contact
 */
class ChannelMessageClientListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config, source of the schema ids.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Stamp the client of the message's contact when it is missing or the contact changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-a-message-names-the-client-of-its-contact
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedEntity(event: $event);
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'message_schema', '');
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return;
		}

		$data = array_merge((array)$entity->getObject(), $event->getModifiedData());
		$contactId = trim((string)($data['contactId'] ?? ''));
		$previous = $this->previousContact(event: $event);
		if ($contactId === '' || (($data['clientId'] ?? '') !== '' && $previous === $contactId)) {
			return;
		}

		$clientId = $this->clientOf(contactId: $contactId);
		if ($clientId === ($data['clientId'] ?? '')) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['clientId' => $clientId]));
	}//end handle()

	/**
	 * The client a contact belongs to.
	 *
	 * RBAC is off on purpose: only the reference is copied, and a message must
	 * name its client whoever sends it.
	 *
	 * @param string $contactId The contact uuid.
	 *
	 * @return string The client uuid, '' for none.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-a-message-names-the-client-of-its-contact
	 */
	public function clientOf(string $contactId): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$contact = $this->objectService->find(id: $contactId, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return '';
		}

		if ($contact === null) {
			return '';
		}

		$data = (array)$contact->getObject();
		return trim((string)($data['client'] ?? ''));
	}//end clientOf()

	/**
	 * The object as it is about to be saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return ObjectEntity The object.
	 */
	private function savedEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end savedEntity()

	/**
	 * The contact of the stored message before an update, '' on create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return string The contact uuid.
	 */
	private function previousContact(ObjectCreatingEvent|ObjectUpdatingEvent $event): string {
		if ($event instanceof ObjectCreatingEvent || $event->getOldObject() === null) {
			return '';
		}

		return trim((string)($event->getOldObject()->getObject()['contactId'] ?? ''));
	}//end previousContact()
}//end class
