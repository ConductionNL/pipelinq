<?php

/**
 * Pipelinq ContactImportService.
 *
 * Service for importing Nextcloud contacts into Pipelinq as client or contact objects.
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
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for importing Nextcloud contacts into Pipelinq objects.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-write-back-sync-mvp
 */
class ContactImportService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ContactDataBuilder $dataBuilder The data builder.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContactDataBuilder $dataBuilder,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Import a Nextcloud contact as a Pipelinq client.
	 *
	 * @param array $ncContact The Nextcloud contact data.
	 * @param string $uid The contact UID.
	 *
	 * @return array The created client object data.
	 *
	 * @throws RuntimeException When `client_schema` is not configured.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function importAsClient(array $ncContact, string $uid): array {
		$data = $this->dataBuilder->buildClientImportData(
			ncContact: $ncContact,
			uid: $uid
		);
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($schemaId === '') {
			throw new RuntimeException('Pipelinq: app-config "client_schema" is not configured.');
		}

		return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
	}//end importAsClient()

	/**
	 * Import a Nextcloud contact as a Pipelinq contact person.
	 *
	 * @param array $ncContact The Nextcloud contact data.
	 * @param string $uid The contact UID.
	 * @param ?string $clientId The optional client ID.
	 *
	 * @return array The created contact object data.
	 *
	 * @throws RuntimeException When `contact_schema` is not configured.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function importAsContact(array $ncContact, string $uid, ?string $clientId): array {
		$data = $this->dataBuilder->buildContactImportData(
			ncContact: $ncContact,
			uid: $uid,
			clientId: $clientId
		);
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($schemaId === '') {
			throw new RuntimeException('Pipelinq: app-config "contact_schema" is not configured.');
		}

		return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
	}//end importAsContact()

	/**
	 * Save object data and return the serialized result.
	 *
	 * Fails closed on an unconfigured register or schema. This write had no
	 * such guard: an empty id is not the same as "no id" to OpenRegister, whose
	 * ObjectService skips setRegister()/setSchema() for an empty value, so the
	 * imported contact would land in whatever register/schema context an
	 * earlier call in the same request left on the shared service instance.
	 * Throwing matches the surrounding import path, which already raises
	 * RuntimeException for unmet preconditions.
	 *
	 * @param array $data The object data to save.
	 * @param string $schemaId The schema ID.
	 *
	 * @return array The serialized result.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 */
	private function saveAndSerialize(array $data, string $schemaId): array {
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Pipelinq: register or schema is not configured for the contact import.');
		}

		// 🔴 AN IMPORT IS NOT ALWAYS A CREATE.
		//
		// This passed `null` as the uuid unconditionally, so every import
		// CREATED. Re-importing one addressbook contact — which is the normal
		// thing to do after editing it in Contacts, and what a re-run of a bulk
		// import does to every row — produced a duplicate client each time,
		// with no error and nothing in the UI to say so.
		//
		// `contactsUid` is the addressbook contact's own UID and is the
		// identity that survives both sides, so it is what an existing object
		// is found by. Found => UPDATE that object; not found => create.
		$existingUuid = $this->findExistingByContactsUid(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $schemaId,
			contactsUid: (string)($data['contactsUid'] ?? '')
		);

		$created = $objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			$existingUuid
		);

		return $this->serializeResult(result: $created);
	}//end saveAndSerialize()

	/**
	 * The uuid of an object already imported from this addressbook contact.
	 *
	 * Returns null when there is none, when the uid is empty, or when the
	 * lookup itself fails — all three mean "no known object", and the caller
	 * then creates. A failed lookup deliberately does NOT abort the import: the
	 * cost of that is the duplicate this method exists to avoid, which is
	 * better than losing the import outright.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 * @param string $contactsUid The addressbook contact UID.
	 *
	 * @return string|null The existing uuid, or null to create.
	 */
	private function findExistingByContactsUid(
		object $objectService,
		string $registerId,
		string $schemaId,
		string $contactsUid,
	): ?string {
		if ($contactsUid === '') {
			return null;
		}

		try {
			// Register/schema go INSIDE `filters`: prepareFindAllConfig() reads
			// them from there and nowhere else, and a top-level pair silently
			// resolves no context and answers [].
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
						'contactsUid' => $contactsUid,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			// No logger on this class, and adding one for a swallowed lookup is
			// not worth the constructor change: the caller creates, which is the
			// pre-existing behaviour, so a failed lookup can only cost the
			// duplicate this method exists to avoid.
			unset($e);
			return null;
		}

		foreach ((array)$rows as $row) {
			$array = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$array = $row->jsonSerialize();
			}

			if (is_array($array) === false) {
				continue;
			}

			$uuid = (string)($array['@self']['id'] ?? $array['id'] ?? '');
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end findExistingByContactsUid()

	/**
	 * Serialize an object or array result to an array.
	 *
	 * @param mixed $result The result to serialize.
	 *
	 * @return array The serialized result.
	 */
	private function serializeResult(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return $result->jsonSerialize();
		}

		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}//end serializeResult()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
