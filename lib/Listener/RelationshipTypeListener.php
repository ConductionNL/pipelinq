<?php

/**
 * Pipelinq RelationshipTypeListener.
 *
 * Before a relationship is stored, sets what its type and target imply: the
 * inverse type and category of the type, and whether the target is a client
 * or a contact. Whoever writes the relationship, these stay right.
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
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RelationshipTypeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Throwable;

/**
 * Derives inverseType, category and toType on a relationship.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-derives-what-a-type-and-target-imply
 */
class RelationshipTypeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RelationshipTypeService $types         The type table.
	 * @param ObjectServiceInterface  $objectService OpenRegister object service.
	 * @param IAppConfig              $appConfig     App config, source of the schema ids.
	 */
	public function __construct(
		private readonly RelationshipTypeService $types,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Set the implied fields on a relationship about to be saved.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-derives-what-a-type-and-target-imply
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedEntity(event: $event);
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'relationship_schema', '');
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return;
		}

		$data = array_merge((array)$entity->getObject(), $event->getModifiedData());
		$expected = $this->types->impliedBy(type: trim((string)($data['type'] ?? '')));
		$toContact = trim((string)($data['toContact'] ?? ''));
		if ($toContact !== '' && $toContact !== $this->previousTarget(event: $event)) {
			$expected['toType'] = $this->partyTypeOf(uuid: $toContact);
		}

		$patch = $this->differences(expected: $expected, data: $data);
		if ($patch === []) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
	}//end handle()

	/**
	 * The expected fields whose stored value differs.
	 *
	 * @param array<string, string> $expected The fields as they should be.
	 * @param array<string, mixed>  $data     The relationship as it is about to be saved.
	 *
	 * @return array<string, string> The fields to set.
	 */
	private function differences(array $expected, array $data): array {
		$patch = [];
		foreach ($expected as $key => $value) {
			if ((string)($data[$key] ?? '') !== $value) {
				$patch[$key] = $value;
			}
		}

		return $patch;
	}//end differences()

	/**
	 * Whether a party is a client or a contact.
	 *
	 * RBAC is off on purpose: only the kind is read, and the relationship must
	 * name it whoever writes it.
	 *
	 * @param string $uuid The party uuid.
	 *
	 * @return string 'client' when a client has this uuid, else 'contact'.
	 */
	private function partyTypeOf(string $uuid): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return 'contact';
		}

		try {
			$client = $this->objectService->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			$client = null;
		}

		if ($client === null) {
			return 'contact';
		}

		return 'client';
	}//end partyTypeOf()

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
	 * The target of the stored relationship before an update, '' on create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return string The target uuid.
	 */
	private function previousTarget(ObjectCreatingEvent|ObjectUpdatingEvent $event): string {
		if ($event instanceof ObjectCreatingEvent || $event->getOldObject() === null) {
			return '';
		}

		return trim((string)($event->getOldObject()->getObject()['toContact'] ?? ''));
	}//end previousTarget()
}//end class
