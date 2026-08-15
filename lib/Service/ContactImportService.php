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
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for importing Nextcloud contacts into Pipelinq objects.
 */
class ContactImportService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ContactDataBuilder $dataBuilder The data builder.
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

		$created = $objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			null
		);

		return $this->serializeResult(result: $created);
	}//end saveAndSerialize()

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
