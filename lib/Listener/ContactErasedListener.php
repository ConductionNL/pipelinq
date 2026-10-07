<?php

/**
 * Pipelinq ContactErasedListener.
 *
 * When a contact or client is deleted, ClientManagementIntegration erases its
 * consent history in pipelinq and asks integriq to drop the contact link
 * while keeping the opt-out (opt-out-before-send REQ-CII-005). Before this
 * listener, onContactDeleted() had no caller.
 *
 * THE ERASURE RUNS ON THE SOFT DELETE, NOT ON THE PURGE. An OpenRegister API
 * delete moves the object to the trash: DeleteObject::delete() sets the
 * deletion metadata (openregister lib/Service/Object/DeleteObject.php:361)
 * and saves through MagicMapper::update() (:374), which dispatches
 * ObjectUpdatedEvent (lib/Db/MagicMapper.php:9922). ObjectDeletedEvent only
 * fires when the trash is purged (lib/Db/MagicMapper.php:10001), which may be
 * never. Ruben (2026-10-07): erase on the soft delete.
 *
 * The purge still runs the erasure, so a contact trashed before this listener
 * learnt the soft delete is erased then. Both steps are idempotent: integriq
 * clears rows that still carry the contact ref (none the second time) and
 * pipelinq's consent history is already gone. A restore out of the trash does
 * not bring the evidence or the contact link back; the opt-out itself was
 * never removed.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
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
	 * Handle a soft deleted or purged object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
	 * @spec openspec/changes/erase-on-soft-delete/specs/consent-in-integriq/spec.md#requirement-a-soft-delete-erases-the-contact-in-integriq-req-cii-008
	 */
	public function handle(Event $event): void {
		$object = $this->erasedObject(event: $event);
		if ($object === null) {
			return;
		}

		$type = $this->schemaMap->resolveEntityType(schemaId: (string)$object->getSchema());
		if ($type !== 'contact' && $type !== 'client') {
			return;
		}

		$this->integration->onContactDeleted(contactId: (string)$object->getUuid());
	}//end handle()

	/**
	 * The object this event takes out of use, or null when it takes none.
	 *
	 * A purge carries the object it removed. An update counts only when it
	 * moves a live object into the trash: an ordinary edit, a restore and an
	 * edit inside the trash erase nothing.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/changes/erase-on-soft-delete/specs/consent-in-integriq/spec.md#requirement-a-soft-delete-erases-the-contact-in-integriq-req-cii-008
	 */
	private function erasedObject(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectDeletedEvent) {
			return $event->getObject();
		}

		if (($event instanceof ObjectUpdatedEvent) === false) {
			return null;
		}

		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($this->isTrashed(object: $new) === false) {
			return null;
		}

		if ($old !== null && $this->isTrashed(object: $old) === true) {
			return null;
		}

		return $new;
	}//end erasedObject()

	/**
	 * Whether an object is in the trash.
	 *
	 * OpenRegister's own answer (ObjectEntity::isSoftDeleted(),
	 * lib/Db/ObjectEntity.php:2046). The raw `deleted` field defaults to `[]`,
	 * so reading it directly would call every object trashed.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when it carries deletion metadata.
	 */
	private function isTrashed(ObjectEntity $object): bool {
		return $object->isSoftDeleted();
	}//end isTrashed()
}//end class
