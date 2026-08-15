<?php

/**
 * Pipelinq ContactVcardWriterService.
 *
 * Service for writing vCard data to Nextcloud addressbooks.
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
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for writing vCard data to Nextcloud addressbooks.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactVcardWriterService {
	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The contacts manager.
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface $logger The logger.
	 * @param RegisterResolverService $registerResolver The register resolver.
	 */
	public function __construct(
		private IContactsManager $contactsManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Write vCard properties to the user's default addressbook.
	 *
	 * @param array $properties The vCard properties.
	 * @param array $objData The Pipelinq object data.
	 * @param string $objectType The object type (client or contact).
	 *
	 * @return ?string The contacts UID or null.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function writeToAddressBook(array $properties, array $objData, string $objectType): ?string {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		if (empty($addressBooks) === true) {
			$this->logger->debug('Pipelinq: No addressbooks available for sync');
			return null;
		}

		$addressBook = reset($addressBooks);

		$existingUid = $objData['contactsUid'] ?? null;
		if ($existingUid !== null && $existingUid !== '') {
			$properties['UID'] = $existingUid;
		}

		try {
			$result = $addressBook->createOrUpdate($properties);
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to sync contact to addressbook',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$contactsUid = $this->extractContactsUid(
			result: $result,
			existingUid: $existingUid
		);

		if ($contactsUid !== null && ($existingUid === null || $existingUid === '')) {
			$this->storeContactsUidOnObject(
				objData: $objData,
				contactsUid: $contactsUid,
				objectType: $objectType
			);
		}

		return $contactsUid;
	}//end writeToAddressBook()

	/**
	 * Write a vCard to the user's default addressbook WITHOUT touching any
	 * Pipelinq object. Used by the contact-FIRST create orchestration, where the
	 * Nextcloud contact must exist (so its UID can satisfy the required
	 * `contactsUid`) BEFORE the client/contact object is saved — there is no
	 * object yet to store the UID back on.
	 *
	 * @param array $properties The vCard properties (FN/EMAIL/TEL/...).
	 * @param ?string $existingUid An existing UID to update in place, or null to create.
	 *
	 * @return ?string The created/updated contact UID, or null when no addressbook
	 *                 is available or the write failed.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function writeVcard(array $properties, ?string $existingUid = null): ?string {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		if (empty($addressBooks) === true) {
			$this->logger->debug('Pipelinq: No addressbooks available for contact provisioning');
			return null;
		}

		$addressBook = reset($addressBooks);

		if ($existingUid !== null && $existingUid !== '') {
			$properties['UID'] = $existingUid;
		}

		try {
			$result = $addressBook->createOrUpdate($properties);
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to provision contact in addressbook',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->extractContactsUid(
			result: $result,
			existingUid: $existingUid
		);
	}//end writeVcard()

	/**
	 * Extract the contacts UID from an addressbook create/update result.
	 *
	 * @param mixed $result The result from createOrUpdate.
	 * @param ?string $existingUid The existing UID if any.
	 *
	 * @return ?string The extracted contacts UID or null.
	 */
	private function extractContactsUid(mixed $result, ?string $existingUid): ?string {
		if (is_array($result) === true && isset($result['UID']) === true) {
			return $result['UID'];
		}

		if (is_string($result) === true) {
			return $result;
		}

		if ($existingUid !== null && $existingUid !== '') {
			return $existingUid;
		}

		return null;
	}//end extractContactsUid()

	/**
	 * Store the contactsUid back on the Pipelinq object.
	 *
	 * @param array $objData The object data.
	 * @param string $contactsUid The contacts UID to store.
	 * @param string $objectType The object type (client or contact).
	 *
	 * @return void
	 */
	private function storeContactsUidOnObject(array $objData, string $contactsUid, string $objectType): void {
		try {
			$objectService = $this->getObjectService();
			$registerId = $this->registerResolver->resolve('contact');
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

			// Fail closed on an unconfigured register or schema. This write had
			// no such guard: an empty id is not the same as "no id" to
			// OpenRegister, whose ObjectService skips setRegister()/setSchema()
			// for an empty value, so the object would be written into whatever
			// register/schema context an earlier call in the same request left
			// on the shared service instance. `$objectType` also reaches the
			// config key by interpolation, so an unexpected type resolves to a
			// key that does not exist and yields the same empty id.
			if ($registerId === '' || $schemaId === '') {
				$this->logger->warning(
					'Pipelinq: refusing to store contactsUid — register or schema is not configured',
					['objectType' => $objectType]
				);
				return;
			}

			$updateData = $objData;
			$updateData['contactsUid'] = $contactsUid;
			$objectService->saveObject(
				$updateData,
				[],
				$registerId,
				$schemaId,
				null
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Pipelinq: Failed to store contactsUid back on object',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end storeContactsUidOnObject()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
