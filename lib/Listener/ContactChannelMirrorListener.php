<?php

/**
 * Pipelinq ContactChannelMirrorListener.
 *
 * Keeps a client's or contact's single `email`/`phone` field equal to the
 * primary entry of its `emails[]`/`phones[]` list, on every save and for every
 * writer, before the object is stored.
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
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-channel-details/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Mirrors the primary email and phone entry into the single fields.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-channel-details/spec.md#requirement-the-server-keeps-the-single-email-and-phone-fields
 */
class ContactChannelMirrorListener implements IEventListener {

	/**
	 * List field => the single field it fills.
	 *
	 * @var array<string, string>
	 */
	private const MIRRORS = ['emails' => 'email', 'phones' => 'phone'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config, source of the schema ids.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Recompute the single fields of a client or contact that is being saved.
	 *
	 * A list that did not change leaves its single field alone, so a writer
	 * that sets only `email` keeps working.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-channel-details/spec.md#requirement-the-server-keeps-the-single-email-and-phone-fields
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedEntity(event: $event);
		if ($this->isPartySchema(schemaId: (string)$entity->getSchema()) === false) {
			return;
		}

		$patch = $this->mirrorPatch(
			data: array_merge((array)$entity->getObject(), $event->getModifiedData()),
			previous: $this->previousData(event: $event),
			creating: $event instanceof ObjectCreatingEvent
		);
		if ($patch === []) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
	}//end handle()

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
	 * The stored data before an update, [] on create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return array<string, mixed> The previous data.
	 */
	private function previousData(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectCreatingEvent || $event->getOldObject() === null) {
			return [];
		}

		return (array)$event->getOldObject()->getObject();
	}//end previousData()

	/**
	 * The single fields to set for the lists that changed.
	 *
	 * @param array<string, mixed> $data     The object as it will be saved.
	 * @param array<string, mixed> $previous The object before the save, [] on create.
	 * @param bool                 $creating Whether the object is new.
	 *
	 * @return array<string, string> The fields to set.
	 */
	private function mirrorPatch(array $data, array $previous, bool $creating): array {
		$patch = [];
		foreach (self::MIRRORS as $listField => $singleField) {
			$list = ($data[$listField] ?? null);
			if (is_array($list) === false
				|| (array_key_exists($listField, $previous) === true && $previous[$listField] === $list)
				|| ($creating === true && $list === [])
			) {
				continue;
			}

			$value = $this->primaryValue(entries: $list);
			if (($data[$singleField] ?? '') !== $value) {
				$patch[$singleField] = $value;
			}
		}

		return $patch;
	}//end mirrorPatch()

	/**
	 * The value of the primary entry, else of the first entry, else ''.
	 *
	 * @param array<mixed> $entries The channel entries.
	 *
	 * @return string The value.
	 */
	private function primaryValue(array $entries): string {
		$first = null;
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$first ??= $entry;
			if (($entry['primary'] ?? false) === true) {
				return (string)($entry['value'] ?? '');
			}
		}

		return (string)($first['value'] ?? '');
	}//end primaryValue()

	/**
	 * Whether a schema id is the client or the contact schema.
	 *
	 * @param string $schemaId The schema id of the object.
	 *
	 * @return bool True for client and contact.
	 */
	private function isPartySchema(string $schemaId): bool {
		if ($schemaId === '') {
			return false;
		}

		foreach (['client_schema', 'contact_schema'] as $key) {
			if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === $schemaId) {
				return true;
			}
		}

		return false;
	}//end isPartySchema()
}//end class
