<?php

/**
 * Pipelinq NoteEventService.
 *
 * Service for triggering notifications and activity events when notes are added.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/entity-notes/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for triggering note-related events and notifications.
 *
 * @spec openspec/specs/entity-notes/spec.md#requirement-notes-crud-on-all-entity-types-mvp
 */
class NoteEventService {
	private const TYPE_MAP = [
		'pipelinq_client' => 'client',
		'pipelinq_contact' => 'contact',
		'pipelinq_lead' => 'lead',
		'pipelinq_request' => 'request',
	];

	/**
	 * Constructor.
	 *
	 * @param NotificationService $notificationService The notification service.
	 * @param ActivityService $activityService The activity service.
	 * @param SettingsService $settingsService The settings service.
	 * @param IUserSession $userSession The user session.
	 * @param ContainerInterface $container DI container (lazy OpenRegister resolve).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private NotificationService $notificationService,
		private ActivityService $activityService,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Trigger notification and activity events for a newly added note.
	 *
	 * @param string $objectType The object type.
	 * @param string $objectId The object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/entity-notes/spec.md
	 */
	public function triggerNoteEvents(string $objectType, string $objectId): void {
		try {
			$entityType = self::TYPE_MAP[$objectType] ?? null;
			if ($entityType === null) {
				return;
			}

			// The `in_array()` guard that stood here listed exactly the four
			// values self::TYPE_MAP can yield, and the null case is already
			// returned above — so it could never reject anything.

			$entityData = $this->fetchEntityData(
				entityType: $entityType,
				objectId: $objectId
			);

			if ($entityData === null) {
				return;
			}

			$this->publishNoteActivity(
				entityType: $entityType,
				entityData: $entityData,
				objectId: $objectId
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to trigger note events',
				[
					'objectType' => $objectType,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end triggerNoteEvents()

	/**
	 * Fetch entity data from OpenRegister for note event context.
	 *
	 * @param string $entityType The entity type.
	 * @param string $objectId The object ID.
	 *
	 * @return ?array The entity data with title and assignee, or null on failure.
	 */
	private function fetchEntityData(string $entityType, string $objectId): ?array {
		$settings = $this->settingsService->getSettings();
		$register = $settings['register'] ?? '';
		$schemaKey = $entityType . '_schema';
		$schema = $settings[$schemaKey] ?? '';

		if ($register === '' || $schema === '') {
			return null;
		}

		// ADR-080 D2/D3: store discovery belongs to OpenRegister. This used to
		// hand-build an objects-API path and fetch it over HTTP, a loopback
		// request out of the instance and back into it, carrying a CSRF token
		// and allow_local_address, purely to read an object this process can
		// already read in-memory. ObjectService is resolved from the container
		// so a missing OpenRegister degrades to null here rather than breaking
		// class loading.
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $objectId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Could not read note entity from OpenRegister',
				[
					'entityType' => $entityType,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		return $this->toArray(row: $object);
	}//end fetchEntityData()

	/**
	 * Normalise an OpenRegister result to a plain array.
	 *
	 * @param mixed $row The value returned by ObjectService.
	 *
	 * @return ?array The object as an array, or null when it cannot be read.
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialized = $row->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}//end toArray()

	/**
	 * Publish activity and notification for a note addition.
	 *
	 * @param string $entityType The entity type.
	 * @param array $entityData The entity data from OpenRegister.
	 * @param string $objectId The object ID.
	 *
	 * @return void
	 */
	private function publishNoteActivity(string $entityType, array $entityData, string $objectId): void {
		$entityTitle = $entityData['title'] ?? $entityType . ' ' . $objectId;
		$assignee = $entityData['assignee'] ?? '';

		$currentUser = $this->userSession->getUser();
		$author = '';
		if ($currentUser !== null) {
			$author = $currentUser->getUID();
		}

		$assigneeOrNull = null;
		if ($assignee !== '') {
			$assigneeOrNull = $assignee;
		}

		$this->activityService->publishNoteAdded(
			entityType: $entityType,
			entityTitle: $entityTitle,
			objectId: $objectId,
			affectedUser: $assigneeOrNull
		);

		if ($assignee !== '') {
			$this->notificationService->notifyNoteAdded(
				entityType: $entityType,
				entityTitle: $entityTitle,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $author
			);
		}
	}//end publishNoteActivity()
}//end class
