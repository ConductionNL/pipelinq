<?php

/**
 * Pipelinq ContactmomentService.
 *
 * Service for contactmoment business operations including permission-checked deletion.
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
 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmoment-update-and-deletion
 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmomentservice-backend
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for contactmoment business operations.
 *
 * Handles permission-checked deletion (only the creating agent or a Nextcloud
 * admin may delete a contactmoment) and the unified outbound-send audit write.
 *
 * Since unify-ticket-supertype a contactmoment is a `ticket` carrying
 * `ticketType: contactmoment` — every read and write here resolves the unified
 * ticket schema through {@see TicketService} and narrows on the discriminator;
 * the legacy `contactmoment_schema` config key is no longer consulted.
 *
 * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class ContactmomentService {
	use EntityAccessorTrait;

	/**
	 * Constructor.
	 *
	 * @param TicketService $ticketService The unified ticket resolver.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private TicketService $ticketService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface The object service.
	 *
	 * @throws \RuntimeException If OpenRegister is not available.
	 * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-2
	 */
	public function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the configured register and schema for contactmomenten.
	 *
	 * Resolves the unified `ticket` schema (unify-ticket-supertype); callers
	 * narrow to contactmomenten with the `ticketType` discriminator.
	 *
	 * @return array{register: string, schema: string} The register and schema IDs.
	 *
	 * @throws \RuntimeException If configuration is missing.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmomentservice-backend
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function getConfig(): array {
		if ($this->ticketService->isConfigured() === false) {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		return [
			'register' => $this->ticketService->getRegisterId(),
			'schema' => $this->ticketService->getSchemaId(),
		];
	}//end getConfig()

	/**
	 * Record an outbound messaging send as a contactmoment (audit).
	 *
	 * The single write path for REQ-OM-006: every successful outbound
	 * WhatsApp / SMS send (agent composer or SLA escalation) is registered
	 * as a `ticket` with `ticketType: contactmoment`, linked to the client.
	 * WhatsApp is stored as `channel: chat` with
	 * `channelMetadata.platform = whatsapp`; SMS uses the `channel: sms` enum
	 * value. The write is log-and-continue: any failure (OpenRegister absent,
	 * schema unconfigured, save error) returns null and MUST never block,
	 * fail, or roll back the send itself.
	 *
	 * The `$subject` / `$summary` / `$agent` arguments keep their caller-facing
	 * names (the messaging adapters pass them by name) but are persisted onto
	 * the ticket fields `title` / `description` / `assignee`.
	 *
	 * @param string $channel `sms` or `chat` (WhatsApp).
	 * @param string $subject Human-readable subject line.
	 * @param string $summary Message body / summary text.
	 * @param array<string, mixed> $channelMetadata Metadata envelope
	 *                                              (platform, direction,
	 *                                              messageId, conversationId,
	 *                                              contactId).
	 * @param string $clientId Linked client UUID, or empty.
	 * @param string $agent Acting agent id
	 *                      (`system:sla-engine` for
	 *                      SLA escalations), or empty.
	 *
	 * @return string|null The contactmoment ticket UUID, or null when skipped/failed.
	 *
	 * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function recordOutboundMessage(
		string $channel,
		string $subject,
		string $summary,
		array $channelMetadata,
		string $clientId = '',
		string $agent = '',
	): ?string {
		try {
			$config = $this->getConfig();
		} catch (\Throwable $e) {
			$this->logger->info(
				'ContactmomentService.recordOutboundMessage: audit skipped (ticket schema unconfigured)',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		// The write is issued directly against the injected ObjectService rather
		// than through TicketService::save(), because this is a best-effort audit
		// side-channel; TicketService still owns the schema resolution and the
		// ticketType discriminator.
		//
		// This used to go through a duck-typed `objectServiceLoose()` that
		// resolved the service from the container and returned null when
		// OpenRegister was absent. The service is now a constructor-injected,
		// non-nullable ObjectServiceInterface, so that helper's try block had
		// been emptied — leaving a dead catch and a read of an undefined
		// `$service` (phpstan/psalm/phpmd all flagged it). Container-absence is
		// now a construction-time failure, so there is nothing to degrade to.
		$objectService = $this->objectService;

		$payload = [
			'ticketType' => TicketService::TYPE_CONTACTMOMENT,
			'title' => $subject,
			'description' => $summary,
			'channel' => $channel,
			'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'channelMetadata' => $channelMetadata,
		];
		if ($clientId !== '') {
			$payload['client'] = $clientId;
		}

		if ($agent !== '') {
			$payload['assignee'] = $agent;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $config['register'],
				schema: $config['schema'],
				uuid: null,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ContactmomentService.recordOutboundMessage: save failed',
				['channel' => $channel, 'exception' => $e->getMessage()]
			);
			return null;
		}

		// The saveObject() contract returns an ObjectEntityInterface, so the array
		// arm was dead (phpstan: "Call to function is_array() with
		// ObjectEntityInterface will always evaluate to false"). Note that
		// getUuid() on a Db\ObjectEntity is
		// served by Entity::__call, so method_exists() is FALSE for it — the
		// outbound message row was persisted and this still returned null
		// (pipelinq#807); readEntityValue() is what handles that.
		$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
		if ($uuid !== '') {
			return $uuid;
		}

		return null;
	}//end recordOutboundMessage()

	/**
	 * Delete a contactmoment with permission checking.
	 *
	 * Only the creating agent or a Nextcloud admin may delete. The target is a
	 * `ticket` narrowed to `ticketType: contactmoment` — a request- or
	 * complaint-type ticket is reported as not-found, so this surface can never
	 * delete another ticket subtype.
	 *
	 * @param string $id The contactmoment ticket UUID.
	 * @param string $currentUserId The ID of the user requesting deletion.
	 *
	 * @return bool True if deleted successfully.
	 *
	 * @throws DoesNotExistException If contactmoment not found.
	 * @throws NotPermittedException If user lacks permission.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmoment-update-and-deletion
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function delete(string $id, string $currentUserId): bool {
		$objectService = $this->getObjectService();
		$config = $this->getConfig();

		// Fetch the object to check ownership.
		$object = $objectService->find(
			id: $id,
			register: $config['register'],
			schema: $config['schema']
		);

		if ($object === null) {
			throw new DoesNotExistException(
				'Contactmoment not found: ' . $id
			);
		}

		$data = $this->entityToArray(entity: $object);

		if (($data['ticketType'] ?? '') !== TicketService::TYPE_CONTACTMOMENT) {
			throw new DoesNotExistException(
				'Contactmoment not found: ' . $id
			);
		}

		// Use the OR-immutable createdBy field (set by the platform on every
		// saveObject/createObject call) rather than the user-mutable `assignee`
		// field. Any caller with OR write-access can stamp `assignee: victim_uid`;
		// `createdBy` is protected by the platform and cannot be overwritten via
		// the public API.
		// ObjectServiceInterface::find() returns an ObjectEntityInterface, so the
		// array arm was dead and getCreatedBy() is not on the contract — the
		// value lives in the payload. Both facts came from phpstan:
		//   Call to an undefined method ObjectEntityInterface::getCreatedBy()
		//   Call to is_array() with ObjectEntityInterface will always evaluate to false
		$payload = $object->getObject();
		$createdBy = (string)($payload['createdBy'] ?? '');

		$isCreator = ($createdBy !== '' && $createdBy === $currentUserId);
		$isAdmin = $this->groupManager->isAdmin($currentUserId);

		if ($isCreator === false && $isAdmin === false) {
			throw new NotPermittedException(
				'Only the creating agent or an admin can delete this contactmoment.'
			);
		}

		$objectService
			->setRegister($config['register'])
			->setSchema($config['schema'])
			->deleteObject(uuid: $id);

		$this->logger->info(
			'Contactmoment deleted',
			[
				'id' => $id,
				'userId' => $currentUserId,
			]
		);

		return true;
	}//end delete()

	/**
	 * Normalise an OpenRegister entity (or already-rendered row) to an array.
	 *
	 * @param mixed $entity The entity returned by ObjectService::find().
	 *
	 * @return array<string, mixed> The rendered object data (empty when unreadable).
	 */
	private function entityToArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end entityToArray()
}//end class
