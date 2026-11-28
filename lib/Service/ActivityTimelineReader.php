<?php

/**
 * Pipelinq ActivityTimelineReader.
 *
 * Reads the raw rows of every activity timeline source: a configured schema,
 * the notes on a record, the WhatsApp/SMS messages of a client's contact
 * persons, a client's appointment bookings and the emails linked to a record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-lists-notes-and-messages
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the raw rows of the activity timeline's sources.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
 */
class ActivityTimelineReader {
	/**
	 * Per-source fetch ceiling. The timeline merges every source, sorts by
	 * date and pages in PHP, so each source's window is capped.
	 */
	private const PER_SCHEMA_CEILING = 500;

	/**
	 * OpenRegister's published reader of an object's linked emails. Looked up
	 * by name, so an OpenRegister without it leaves the mail kind empty.
	 */
	private const EMAIL_LINK_READER = 'OCA\\OpenRegister\\Contract\\EmailLinkReaderInterface';

	/**
	 * OpenRegister's calendar leaf read service. Looked up by name, so an
	 * OpenRegister without it leaves the leaf's meetings off the timeline.
	 */
	private const CALENDAR_LEAF = 'OCA\\OpenRegister\\Service\\CalendarLinkService';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param NotesService $notesService Reader of the notes on a record (Nextcloud comments).
	 * @param ContainerInterface $container The DI container, for OpenRegister's email link reader.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly NotesService $notesService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Query a single OpenRegister schema using a set of equality filters.
	 *
	 * @param string $registerId The register ID.
	 * @param string $schemaId The schema ID.
	 * @param array<string,mixed> $filters Field equality filters.
	 *
	 * @return array<int,array<string,mixed>> The raw object arrays.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-3
	 */
	public function query(string $registerId, string $schemaId, array $filters): array {
		$filters = array_merge(
			[
				'register' => $registerId,
				'schema' => $schemaId,
			],
			$filters
		);

		return $this->findLogged(filters: $filters, schemaId: $schemaId);
	}//end query()

	/**
	 * The objects matching the filters, none (logged) when the read fails.
	 *
	 * @param array<string,mixed> $filters  The register, schema and field equality filters.
	 * @param string              $schemaId The schema ID, for the log.
	 *
	 * @return array<int,array<string,mixed>> The raw object arrays.
	 */
	private function findLogged(array $filters, string $schemaId): array {
		try {
			$results = $this->objectService->findAll(['filters' => $filters, 'limit' => self::PER_SCHEMA_CEILING]);

			return $this->normaliseResultset(results: $results);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineReader: failed to query schema',
				[
					'exception' => $e->getMessage(),
					'schemaId' => $schemaId,
				]
			);
			return [];
		}//end try
	}//end findLogged()

	/**
	 * Normalise an OpenRegister findAll result into an array of plain arrays.
	 *
	 * @param mixed $results The raw ObjectService->findAll return value.
	 *
	 * @return array<int,array<string,mixed>> A list of plain object arrays.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-activity-timeline/tasks.md#task-3
	 */
	private function normaliseResultset(mixed $results): array {
		if (is_array($results) === false) {
			return [];
		}

		$output = [];
		foreach ($results as $item) {
			$normalised = $this->normaliseResultItem(item: $item);
			if ($normalised !== null) {
				$output[] = $normalised;
			}
		}//end foreach

		return $output;
	}//end normaliseResultset()

	/**
	 * Normalise a single result-set entry into an array payload.
	 *
	 * Arrays pass through unchanged; OpenRegister entities are unwrapped via
	 * getObject() (promoting id/uuid when missing); other objects are cast to
	 * array. Scalars yield null.
	 *
	 * @param mixed $item The raw result entry.
	 *
	 * @return array<string,mixed>|null The normalised payload, or null to skip.
	 */
	private function normaliseResultItem(mixed $item): ?array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === false) {
			return null;
		}

		if (method_exists($item, 'getObject') === true) {
			$value = $item->getObject();
			if (is_array($value) === true) {
				// Promote id/uuid into the object payload if missing.
				if (isset($value['id']) === false && method_exists($item, 'getUuid') === true) {
					$value['id'] = $item->getUuid();
				}

				return $value;
			}
		}

		return (array)$item;
	}//end normaliseResultItem()

	/**
	 * The notes on a record.
	 *
	 * @param string $entityId The record's uuid.
	 *
	 * @return array<int,array<string,mixed>> The notes as NotesService reads them.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-lists-notes-and-messages
	 */
	public function notes(string $entityId): array {
		return $this->notesService->getNotes(objectType: 'openregister', objectId: $entityId);
	}//end notes()

	/**
	 * The WhatsApp/SMS messages of an entity. Messages belong to a person
	 * (`contactId`), so a client's messages are those of its contact persons.
	 *
	 * @param string $entityType The entity type (client|contact).
	 * @param string $entityId The entity UUID.
	 * @param string $registerId The register ID.
	 *
	 * @return array<int,array<string,mixed>> The raw message objects.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-lists-notes-and-messages
	 */
	public function messages(string $entityType, string $entityId, string $registerId): array {
		$messageSchema = $this->schemaId(key: 'message_schema');
		if ($messageSchema === '') {
			return [];
		}

		$origin = new ActivityOrigin(entityType: $entityType, entityId: $entityId);
		$messages = [];
		foreach ($this->contactIdsOf(origin: $origin, registerId: $registerId) as $contactId) {
			$messages = array_merge(
				$messages,
				$this->query(registerId: $registerId, schemaId: $messageSchema, filters: ['contactId' => $contactId])
			);
		}

		return $messages;
	}//end messages()

	/**
	 * The contact persons whose messages belong to an entity: a contact itself, a client's contacts.
	 *
	 * @param ActivityOrigin $origin     The entity type (client|contact) and UUID.
	 * @param string         $registerId The register ID.
	 *
	 * @return array<int|string,string> The contact ids.
	 */
	private function contactIdsOf(ActivityOrigin $origin, string $registerId): array {
		$contactIds = [];
		if ($origin->entityType === 'contact') {
			$contactIds = [$origin->entityId];
		}

		$contactSchema = $this->schemaId(key: 'contact_schema');
		if ($origin->entityType === 'client' && $contactSchema !== '') {
			$contacts = $this->query(registerId: $registerId, schemaId: $contactSchema, filters: ['client' => $origin->entityId]);
			$contactIds = array_filter(array_map(static fn (array $contact): string => (string)($contact['id'] ?? ''), $contacts));
		}

		return $contactIds;
	}//end contactIdsOf()

	/**
	 * A client's appointment bookings, each with its service's name as
	 * `serviceName`.
	 *
	 * @param string $entityType The entity type; only a client has bookings.
	 * @param string $entityId The client UUID.
	 * @param string $registerId The register ID.
	 *
	 * @return array<int,array<string,mixed>> The raw booking objects.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-lists-bookings-and-linked-mail
	 */
	public function bookings(string $entityType, string $entityId, string $registerId): array {
		$bookingSchema = $this->schemaId(key: 'booking_schema');
		if ($entityType !== 'client' || $bookingSchema === '') {
			return [];
		}

		$bookings = $this->query(registerId: $registerId, schemaId: $bookingSchema, filters: ['customerId' => $entityId]);
		return $this->withServiceNames(bookings: $bookings, registerId: $registerId);
	}//end bookings()

	/**
	 * The bookings, each with its service's name as `serviceName`.
	 *
	 * @param array<int,array<string,mixed>> $bookings   The raw booking objects.
	 * @param string                         $registerId The register ID.
	 *
	 * @return array<int,array<string,mixed>> The bookings with their service names.
	 */
	private function withServiceNames(array $bookings, string $registerId): array {
		$serviceSchema = $this->schemaId(key: 'service_schema');
		$services = new RegisterSchema(register: $registerId, schema: $serviceSchema);
		$names = [];
		foreach ($bookings as $index => $booking) {
			$serviceId = (string)($booking['serviceId'] ?? '');
			if ($serviceId !== '' && $serviceSchema !== '' && array_key_exists($serviceId, $names) === false) {
				$names[$serviceId] = $this->serviceName(serviceId: $serviceId, services: $services);
			}

			$bookings[$index]['serviceName'] = $names[$serviceId] ?? null;
		}

		return $bookings;
	}//end withServiceNames()

	/**
	 * The emails linked to a record, through OpenRegister's published reader.
	 * An OpenRegister without that reader, or a failing read, gives none.
	 *
	 * @param string $entityId The record's uuid.
	 *
	 * @return array<int,array<string,mixed>> The linked emails.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-the-timeline-lists-bookings-and-linked-mail
	 */
	public function linkedEmails(string $entityId): array {
		// Asked with get(), not has(): an app container answers has() false
		// for another app's registered alias while get() resolves it.
		try {
			$reader = $this->container->get(self::EMAIL_LINK_READER);
		} catch (ContainerExceptionInterface) {
			return [];
		}

		try {
			$page = $reader->getLinkedEmails(objectUuid: $entityId, limit: 200);
		} catch (Throwable $e) {
			$this->logger->error('ActivityTimelineReader: failed to read linked emails', ['exception' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter($page['items'] ?? [], 'is_array'));
	}//end linkedEmails()

	/**
	 * The calendar events linked to a record, read live through OpenRegister's
	 * calendar leaf. Never stored: the leaf's link table is the record. An
	 * OpenRegister without the leaf, or a failing read, gives none.
	 *
	 * @param string $entityId The record's uuid.
	 *
	 * @return array<int,array<string,mixed>> The leaf's event rows.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-leaf-linked-events-appear-on-the-record-timeline
	 */
	public function linkedEvents(string $entityId): array {
		try {
			if ($this->container->has(self::CALENDAR_LEAF) === false) {
				return [];
			}

			$leaf = $this->container->get(self::CALENDAR_LEAF);
			if (is_object($leaf) === false || method_exists($leaf, 'getLinkedEvents') === false) {
				return [];
			}

			$events = $leaf->getLinkedEvents(objectUuid: $entityId);
		} catch (Throwable $e) {
			$this->logger->error('ActivityTimelineReader: failed to read linked calendar events', ['exception' => $e->getMessage()]);
			return [];
		}

		if (is_array($events) === false) {
			return [];
		}

		return array_values(array_filter($events, 'is_array'));
	}//end linkedEvents()

	/**
	 * The name of an appointment service, or null when it cannot be read.
	 *
	 * @param string         $serviceId The service UUID.
	 * @param RegisterSchema $services  The register and service schema IDs.
	 *
	 * @return string|null The name.
	 */
	private function serviceName(string $serviceId, RegisterSchema $services): ?string {
		try {
			$service = $this->objectService->find(
				id: $serviceId,
				options: new ObjectFindOptions(register: $services->register, schema: $services->schema)
			);
		} catch (Throwable) {
			return null;
		}

		$name = $service?->getObject()['name'] ?? null;
		if (is_string($name) === false || $name === '') {
			return null;
		}

		return $name;
	}//end serviceName()

	/**
	 * A configured schema id.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string The id, or '' when not configured.
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
	}//end schemaId()
}//end class
