<?php

/**
 * Pipelinq SurveyDispatchListener.
 *
 * Turns a ticket that reaches a status a survey rule names into satisfaction
 * survey invitations, by calling SurveyDispatchService::onInteractionCompleted().
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatch satisfaction surveys when a ticket reaches a status a rule names.
 *
 * The dispatch service existed, with tests, and nothing called it, so no
 * survey was ever sent (pipelinq#2072). This listener is that caller.
 *
 * ADR-078: the ticket is already stored when this runs. handle() only filters
 * in memory (a ticket, whose status changed, that an enabled rule names) and
 * queues the work; the reads and the invitation writes run in
 * {@see DeferredObjectListenerJob} under the user who closed the ticket.
 *
 * Delivery is at-least-once, so the deferred pass reconciles: a ticket whose
 * status moved on, or that already has an invitation, is left alone.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */
class SurveyDispatchListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'survey-dispatch';

	/**
	 * Ticket discriminator to the entity type a dispatch rule names.
	 *
	 * The rule vocabulary is the spec's (contactmoment, request, complaint);
	 * the discriminator renamed `contactmoment` to `interaction`, so the one
	 * that differs is translated here.
	 *
	 * @var array<string, string>
	 */
	private const RULE_ENTITY_TYPES = [
		'request' => 'request',
		'complaint' => 'complaint',
		'interaction' => 'contactmoment',
	];

	/**
	 * Constructor.
	 *
	 * @param SurveyDispatchService $dispatchService Matches rules and writes invitations.
	 * @param SchemaMapService $schemaMapService Schema id to entity type.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, holding the register and contact schema.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly SurveyDispatchService $dispatchService,
		private readonly SchemaMapService $schemaMapService,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the dispatch for a ticket that reached a status a rule names.
	 *
	 * Never throws: the ticket's save must not be affected by a survey.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entry = $this->completionEntry(event: $event);
			if ($entry === null) {
				return;
			}

			$this->deferral->defer(
				jobClass: DeferredObjectListenerJob::class,
				entry: $entry,
				dedupeKey: self::HANDLER_KEY . '|' . $entry['uuid']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyDispatchListener: the survey dispatch could not be queued; the ticket is unaffected',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Write the invitations the completed ticket earns.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$status = (string)($entry['status'] ?? '');
		$entityType = (string)($entry['entityType'] ?? '');

		$ticket = $this->read(uuid: $uuid, schema: (string)($entry['schema'] ?? ''));
		if ($ticket === null || trim((string)($ticket['status'] ?? '')) !== $status) {
			// Gone, or moved on since the save (ADR-078 Rule 7).
			return;
		}

		// At-least-once delivery: one completion invites once.
		if ($this->dispatchService->read(filters: ['linkedEntityId' => $uuid]) !== []) {
			return;
		}

		$ticket['id'] = $uuid;
		$this->dispatchService->onInteractionCompleted(
			entityType: $entityType,
			entity: $ticket,
			contact: $this->contactFor(ticket: $ticket),
		);
	}//end runDeferredWork()

	/**
	 * The deferral entry for an event that completes a ticket, or null.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return array<string, string>|null The entry, or null when no rule applies.
	 */
	private function completionEntry(ObjectCreatedEvent|ObjectUpdatedEvent $event): ?array {
		$entity = $event->getObject();
		$previousStatus = '';
		if ($event instanceof ObjectUpdatedEvent) {
			$entity = $event->getNewObject();
			$previous = $event->getOldObject();
			if ($previous !== null) {
				$previousStatus = trim((string)($previous->getObject()['status'] ?? ''));
			}
		}

		$schemaId = (string)$entity->getSchema();
		if ($this->schemaMapService->resolveEntityType(schemaId: $schemaId) !== 'ticket') {
			return null;
		}

		$data = $entity->getObject();
		$status = trim((string)($data['status'] ?? ''));
		$entityType = (self::RULE_ENTITY_TYPES[(string)($data['ticketType'] ?? '')] ?? '');
		$uuid = (string)$entity->getUuid();
		if ($status === '' || $status === $previousStatus || $entityType === '' || $uuid === '') {
			return null;
		}

		$rules = $this->dispatchService->matchingRules(
			entityType: $entityType,
			status: $status,
			channel: trim((string)($data['channel'] ?? '')),
		);
		if ($rules === []) {
			return null;
		}

		return [
			'handler' => self::HANDLER_KEY,
			'uuid' => $uuid,
			'schema' => $schemaId,
			'entityType' => $entityType,
			'status' => $status,
		];
	}//end completionEntry()

	/**
	 * The ticket's contact, shaped as the dispatch service reads a recipient.
	 *
	 * A ticket without a readable contact still goes to the service, which
	 * records the invitation as suppressed rather than dropping it.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return array<string, mixed> The contact, or only the ticket's client.
	 */
	private function contactFor(array $ticket): array {
		$client = ['client' => (string)($ticket['client'] ?? '')];
		$uuid = trim((string)($ticket['contact'] ?? ''));
		if ($uuid === '') {
			return $client;
		}

		$contact = $this->read(
			uuid: $uuid,
			schema: $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '')
		);
		if ($contact === null) {
			return $client;
		}

		return array_merge($client, ['id' => $uuid], $contact);
	}//end contactFor()

	/**
	 * Read one object's data, or null when it is gone or unreadable.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $schema The schema id.
	 *
	 * @return array<string, mixed>|null The data.
	 */
	private function read(string $uuid, string $schema): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($uuid === '' || $schema === '' || $register === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $uuid, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyDispatchListener: an object could not be read for the survey dispatch',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->getObject();
	}//end read()
}//end class
