<?php

/**
 * Pipelinq RelationshipInverseListener.
 *
 * Keeps the inverse row of every relationship: A is B's parent, so B is A's
 * child. Every writer (the detail page, the API, an import) gets the same
 * pair, and a half-failed browser save can no longer leave a one-way link.
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
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\RelationshipInverseService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, updates and deletes the inverse row of a relationship.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
 */
class RelationshipInverseListener implements IEventListener, DeferredObjectWork {

	/**
	 * Allow-list key of this listener in the deferred job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'relationship-inverse';

	/**
	 * Constructor.
	 *
	 * @param RelationshipInverseService $inverses Writes the inverse rows.
	 * @param ListenerDeferralService    $deferral Queues the work onto the deferred job.
	 * @param LoggerInterface            $logger   PSR logger.
	 */
	public function __construct(
		private readonly RelationshipInverseService $inverses,
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the inverse work for a relationship that was created, updated or deleted.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
	 */
	public function handle(Event $event): void {
		try {
			$entry = $this->entryFor(event: $event);
			if ($entry === null) {
				return;
			}

			$this->deferral->defer(
				jobClass: DeferredObjectListenerJob::class,
				entry: $entry,
				dedupeKey: self::HANDLER_KEY . '|' . $entry['action'] . '|' . $entry['uuid']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RelationshipInverseListener: the inverse relationship could not be queued; the relationship itself is saved',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Bring the inverse row in line with the relationship as it is stored now.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
	 */
	public function runDeferredWork(array $entry): void {
		if (($entry['action'] ?? '') === 'delete') {
			$this->inverses->removeInverse(
				fromContact: (string)($entry['fromContact'] ?? ''),
				toContact: (string)($entry['toContact'] ?? ''),
				inverseType: (string)($entry['inverseType'] ?? '')
			);
			return;
		}

		// A relationship moved to another party leaves no inverse at the old one.
		$this->inverses->removeInverse(
			fromContact: (string)($entry['movedFromContact'] ?? ''),
			toContact: (string)($entry['movedToContact'] ?? ''),
			inverseType: (string)($entry['previousInverseType'] ?? '')
		);
		$this->inverses->syncInverse(
			uuid: (string)($entry['uuid'] ?? ''),
			previousInverseType: (string)($entry['previousInverseType'] ?? '')
		);
	}//end runDeferredWork()

	/**
	 * Build the deferral entry for an event, or null when it is not a relationship change.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, string>|null The entry.
	 */
	private function entryFor(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent) {
			return $this->entry(entity: $event->getObject(), action: 'save');
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return $this->updateEntry(event: $event);
		}

		if ($event instanceof ObjectDeletedEvent) {
			return $this->deleteEntry(entity: $event->getObject());
		}

		return null;
	}//end entryFor()

	/**
	 * The entry for an update: a move into the trash deletes, an edit of the pair saves.
	 *
	 * @param ObjectUpdatedEvent $event The update event.
	 *
	 * @return array<string, string>|null The entry, null when nothing the inverse depends on changed.
	 */
	private function updateEntry(ObjectUpdatedEvent $event): ?array {
		$entity = $event->getNewObject();
		$previous = $event->getOldObject();
		if ($entity->isSoftDeleted() === true) {
			// An API delete is a soft delete, reported as an update into the trash.
			if ($previous !== null && $previous->isSoftDeleted() === true) {
				return null;
			}

			return $this->deleteEntry(entity: $entity);
		}

		$previousData = [];
		if ($previous !== null) {
			$previousData = (array)$previous->getObject();
		}

		if ($previousData !== [] && $this->pairFields(data: $previousData) === $this->pairFields(data: (array)$entity->getObject())) {
			return null;
		}

		$entry = $this->entry(entity: $entity, action: 'save');
		if ($entry !== null) {
			$entry['previousInverseType'] = (string)($previousData['inverseType'] ?? '');
			$entry = array_merge($entry, $this->movedFrom(previous: $previousData, current: (array)$entity->getObject()));
		}

		return $entry;
	}//end updateEntry()

	/**
	 * The old pair of a relationship whose parties changed, for its inverse to be removed.
	 *
	 * @param array<string, mixed> $previous The relationship before the edit.
	 * @param array<string, mixed> $current  The relationship after the edit.
	 *
	 * @return array<string, string> `movedFromContact` and `movedToContact`, [] when the pair stayed.
	 */
	private function movedFrom(array $previous, array $current): array {
		$from = (string)($previous['fromContact'] ?? '');
		$toContact = (string)($previous['toContact'] ?? '');
		if ($from === (string)($current['fromContact'] ?? '') && $toContact === (string)($current['toContact'] ?? '')) {
			return [];
		}

		return ['movedFromContact' => $from, 'movedToContact' => $toContact];
	}//end movedFrom()

	/**
	 * The delete entry for a relationship, carrying the pair it named.
	 *
	 * @param ObjectEntity $entity The deleted object.
	 *
	 * @return array<string, string>|null The entry, null for any other schema.
	 */
	private function deleteEntry(ObjectEntity $entity): ?array {
		$entry = $this->entry(entity: $entity, action: 'delete');
		if ($entry === null) {
			return null;
		}

		$data = (array)$entity->getObject();
		$entry['fromContact'] = (string)($data['fromContact'] ?? '');
		$entry['toContact'] = (string)($data['toContact'] ?? '');
		$entry['inverseType'] = (string)($data['inverseType'] ?? $data['type'] ?? '');
		return $entry;
	}//end deleteEntry()

	/**
	 * The base entry for a relationship entity, or null for any other schema.
	 *
	 * @param ObjectEntity $entity The object of the event.
	 * @param string       $action `save` or `delete`.
	 *
	 * @return array<string, string>|null The entry.
	 */
	private function entry(ObjectEntity $entity, string $action): ?array {
		$schema = $this->inverses->schemaId();
		$uuid = (string)$entity->getUuid();
		if ($schema === '' || $uuid === '' || (string)$entity->getSchema() !== $schema) {
			return null;
		}

		return ['handler' => self::HANDLER_KEY, 'uuid' => $uuid, 'action' => $action];
	}//end entry()

	/**
	 * The fields of a row that its inverse depends on.
	 *
	 * @param array<string, mixed> $data A relationship row.
	 *
	 * @return array<string, mixed> Those fields, missing ones as null.
	 */
	private function pairFields(array $data): array {
		$fields = [];
		$names = array_merge(['fromContact', 'toContact', 'fromType', 'toType', 'type', 'inverseType'], RelationshipInverseService::SHARED_FIELDS);
		foreach ($names as $field) {
			$fields[$field] = ($data[$field] ?? null);
		}

		return $fields;
	}//end pairFields()

}//end class
