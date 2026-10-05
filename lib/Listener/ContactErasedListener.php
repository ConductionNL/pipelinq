<?php

/**
 * Pipelinq ContactErasedListener.
 *
 * When a contact or client is deleted, ClientManagementIntegration erases its
 * consent history in pipelinq and asks integriq to drop the contact link
 * while keeping the opt-out (opt-out-before-send REQ-CII-005). Before this
 * listener, onContactDeleted() had no caller.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Pipelinq\Service\ClientManagementIntegration;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contact and client deletion reaches the consent stores.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
 */
class ContactErasedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ClientManagementIntegration $integration Erases the consent history.
	 * @param SchemaMapService            $schemaMap   Tells a contact from other objects.
	 */
	public function __construct(
		private readonly ClientManagementIntegration $integration,
		private readonly SchemaMapService $schemaMap,
	) {
	}//end __construct()

	/**
	 * Handle a deleted object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$type = $this->schemaMap->resolveEntityType(schemaId: (string)$object->getSchema());
		if ($type !== 'contact' && $type !== 'client') {
			return;
		}

		$this->integration->onContactDeleted(contactId: (string)$object->getUuid());
	}//end handle()
}//end class
