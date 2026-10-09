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
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
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
	 * @param LoggerInterface $logger The logger.
	 * @param RegisterResolverService $registerResolver The register resolver.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
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

		$existingUid = $objData['contactsUid'] ?? null;
		$target = $this->resolveTarget(
			addressBooks: $addressBooks,
			properties: $properties,
			existingUid: $existingUid
		);
		if ($target === null) {
			return null;
		}

		[$addressBook, $properties] = $target;

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

		$target = $this->resolveTarget(
			addressBooks: $addressBooks,
			properties: $properties,
			existingUid: $existingUid
		);
		if ($target === null) {
			return null;
		}

		[$addressBook, $properties] = $target;

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
	 * Pick the addressbook and the properties for a create or an update.
	 *
	 * 🔴 A UID ALONE DOES NOT UPDATE A CARD. Nextcloud's
	 * AddressBookImpl::createOrUpdate() updates only when the properties carry
	 * the card's `URI`; without it the call creates a new card, and
	 * CardDavBackend::createCard() refuses a second card with the same UID
	 * ("VCard object with uid already exists in this addressbook collection").
	 * Every edit of a client that already had a contact failed that way, so the
	 * contact kept its old name, email and phone.
	 *
	 * So for a known UID this looks the card up in each addressbook and updates
	 * it where it lives, by its URI. Only when no addressbook holds the UID
	 * (the card was deleted) is a new card made under that UID, in the first
	 * writable addressbook that is not the system addressbook.
	 *
	 * @param array   $addressBooks The user's addressbooks (IAddressBook[]).
	 * @param array   $properties   The vCard properties.
	 * @param ?string $existingUid  The linked contact UID, if any.
	 *
	 * @return ?array{0: IAddressBook, 1: array} The addressbook and the properties
	 *                                     to write, or null when the card is
	 *                                     read-only or no addressbook can take it.
	 *
	 * @spec openspec/changes/round4-contact-write-back/specs/contacts-sync/spec.md#requirement-write-back-updates-the-existing-card
	 */
	private function resolveTarget(array $addressBooks, array $properties, ?string $existingUid): ?array {
		if ($existingUid !== null && $existingUid !== '') {
			$properties['UID'] = $existingUid;

			foreach ($addressBooks as $book) {
				$uri = $this->findCardUri(addressBook: $book, uid: $existingUid);
				if ($uri === null) {
					continue;
				}

				if ($this->isWritable(addressBook: $book) === false) {
					$this->logger->warning(
						'Pipelinq: linked contact lives in a read-only addressbook, write-back skipped',
						['uid' => $existingUid]
					);
					return null;
				}

				$properties['URI'] = $uri;
				return [$book, $properties];
			}
		}//end if

		foreach ($addressBooks as $book) {
			if ($this->isWritable(addressBook: $book) === true) {
				return [$book, $properties];
			}
		}

		$this->logger->warning('Pipelinq: no writable addressbook for contact sync');
		return null;
	}//end resolveTarget()

	/**
	 * The URI of the card with this UID in an addressbook, or null.
	 *
	 * @param IAddressBook $addressBook The addressbook.
	 * @param string $uid         The vCard UID.
	 *
	 * @return ?string The card URI, or null when the addressbook does not hold it.
	 */
	private function findCardUri(IAddressBook $addressBook, string $uid): ?string {
		try {
			$hits = $addressBook->search($uid, ['UID'], ['limit' => 5, 'wildcard' => false]);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Pipelinq: contact lookup by UID failed',
				['uid' => $uid, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($hits as $hit) {
			// The UID search property matches the stored UID; compare it
			// anyway, since a backend may still answer a partial match.
			$hitUid = $hit['UID'] ?? null;
			if (is_array($hitUid) === true) {
				$hitUid = reset($hitUid);
			}

			if ((string)$hitUid === $uid && isset($hit['URI']) === true && $hit['URI'] !== '') {
				return (string)$hit['URI'];
			}
		}

		return null;
	}//end findCardUri()

	/**
	 * Whether pipelinq may write cards in this addressbook.
	 *
	 * @param IAddressBook $addressBook The addressbook.
	 *
	 * @return bool True for a writable, non-system addressbook.
	 */
	private function isWritable(IAddressBook $addressBook): bool {
		if ($addressBook->isSystemAddressBook() === true) {
			return false;
		}

		return ($addressBook->getPermissions() & Constants::PERMISSION_UPDATE) !== 0;
	}//end isWritable()

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
