<?php

/**
 * Pipelinq ContactSyncService.
 *
 * Service for searching and importing Nextcloud contacts into Pipelinq.
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
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactSyncServiceInterface;
use OCA\Pipelinq\Service\Support\FirstValue;
use OCA\Pipelinq\Util\InjectedObjectService;
use OCA\Pipelinq\Util\ResultToArray;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Service for searching and importing Nextcloud contacts into Pipelinq.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-write-back-sync-mvp
 */
class ContactSyncService implements ContactSyncServiceInterface {
	use InjectedObjectService;
	use FirstValue;
	use ResultToArray;

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The contacts manager.
	 * @param ContactImportService $contactImportService The contact import service.
	 * @param ContactVcardService $contactVcardService The vCard sync service.
	 * @param ContactLinkedUidsService $linkedUidsService The linked UIDs service.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param VcardRevision $revisions Reads a card's REV.
	 */
	public function __construct(
		private IContactsManager $contactsManager,
		private ContactImportService $contactImportService,
		private ContactVcardService $contactVcardService,
		private ContactLinkedUidsService $linkedUidsService,
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly VcardRevision $revisions,
	) {
	}//end __construct()

	/**
	 * Contact-FIRST create of a client/contact: provision (resolve or create)
	 * the authoritative Nextcloud addressbook contact from the create-form
	 * fields, then save the object with the resulting `contactsUid` and the
	 * denormalised identity mirror.
	 *
	 * Without this, every UI create is rejected 400 by OpenRegister because the
	 * `client`/`contact` schema marks `contactsUid` REQUIRED but no create
	 * surface can supply it — `contactsUid` is resolved/created via
	 * ContactVcardService, never minted locally (client-contact unification).
	 *
	 * @param string $objectType The object type ('client' or 'contact').
	 * @param array $form The raw create-form fields (name/type/email/phone/...).
	 *
	 * @return array The created object data (serialised).
	 *
	 * @throws RuntimeException When name is missing or the contact cannot be provisioned.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function createWithContact(string $objectType, array $form): array {
		$this->assertCreatable(objectType: $objectType, form: $form);

		$provision = $this->contactVcardService->provisionContactFromForm(
			form: $form,
			objectType: $objectType
		);

		if ($provision === null) {
			throw new RuntimeException('Could not provision the Nextcloud contact -- is the Contacts app enabled?');
		}

		$payload = $this->identityPayload(form: $form, provision: $provision);
		$created = $this->saveCreated(objectType: $objectType, payload: $payload);

		return $this->serializeResult(result: $created);
	}//end createWithContact()

	/**
	 * Reject an unknown object type or a create form without a name.
	 *
	 * @param string $objectType The object type ('client' or 'contact').
	 * @param array $form The raw create-form fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the type is invalid or the name is missing.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	private function assertCreatable(string $objectType, array $form): void {
		if (in_array($objectType, ['client', 'contact'], true) === false) {
			throw new RuntimeException('Invalid objectType -- must be client or contact');
		}

		if (trim((string)($form['name'] ?? '')) === '') {
			throw new RuntimeException('Name is required');
		}
	}//end assertCreatable()

	/**
	 * The object payload: the caller's fields plus the resolved contact identity.
	 *
	 * @param array $form The raw create-form fields.
	 * @param array $provision The provisioned contact (contactsUid, name, email, phone).
	 *
	 * @return array
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	private function identityPayload(array $form, array $provision): array {
		// Build the object payload: caller fields + the resolved identity. The
		// denormalised name/email/phone mirror the authoritative contact.
		$payload = $form;
		unset($payload['id'], $payload['@self']);
		$payload['contactsUid'] = $provision['contactsUid'];
		$payload['name'] = $provision['name'];
		if ($provision['email'] !== '') {
			$payload['email'] = $provision['email'];
		}

		if ($provision['phone'] !== '') {
			$payload['phone'] = $provision['phone'];
		}

		return $payload;
	}//end identityPayload()

	/**
	 * Save the new object in the schema configured for its type.
	 *
	 * @param string $objectType The object type ('client' or 'contact').
	 * @param array $payload The object payload.
	 *
	 * @return mixed The saved object.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	private function saveCreated(string $objectType, array $payload) {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, "{$objectType}_schema", '');

		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Pipelinq register or schema is not configured');
		}

		return $this->getObjectService()->saveObject(
			$payload,
			[],
			$registerId,
			$schemaId,
			null
		);
	}//end saveCreated()

	/**
	 * Search Nextcloud addressbooks for contacts matching a query.
	 * Returns results with an `alreadyLinked` flag if a Pipelinq object has the same contactsUid.
	 *
	 * @param string $query The search query.
	 *
	 * @return array The matching contacts.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function searchContacts(string $query): array {
		if ($this->contactsManager->isEnabled() === false) {
			return [];
		}

		$results = $this->contactsManager->search($query, ['FN', 'EMAIL', 'TEL', 'ORG'], ['limit' => 50]);

		$linkedUids = $this->linkedUidsService->getLinkedContactsUids();

		return $this->buildContactResults(
			results: $results,
			linkedUids: $linkedUids
		);
	}//end searchContacts()

	/**
	 * Import a Nextcloud contact into Pipelinq as a client or contact.
	 *
	 * @param string $uid The contact UID.
	 * @param string $addressBookKey The addressbook key.
	 * @param string $type The import type (client or contact).
	 * @param ?string $clientId The optional client ID for contact imports.
	 *
	 * @return array The created object data.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $addressBookKey kept for future per-book import support
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function importContact(string $uid, string $addressBookKey, string $type = 'client', ?string $clientId = null): array {
		return $this->importAs(ncContact: $this->requireContact(uid: $uid), import: ['uid' => $uid, 'type' => $type, 'clientId' => $clientId]);
	}//end importContact()

	/**
	 * Import a read Nextcloud contact as a client or as a contact.
	 *
	 * @param array                                                 $ncContact The Nextcloud contact.
	 * @param array{uid: string, type: string, clientId: ?string} $import    The contact UID, the import type and the optional client ID.
	 *
	 * @return array The created object data.
	 */
	private function importAs(array $ncContact, array $import): array {
		$uid = $import['uid'];
		if ($import['type'] === 'client') {
			return $this->contactImportService->importAsClient(
				ncContact: $ncContact,
				uid: $uid
			);
		}

		return $this->contactImportService->importAsContact(
			ncContact: $ncContact,
			uid: $uid,
			clientId: $import['clientId']
		);
	}//end importAs()

	/**
	 * The Nextcloud contact with this UID.
	 *
	 * @param string $uid The contact UID.
	 *
	 * @return array The contact.
	 *
	 * @throws RuntimeException When Contacts is unavailable or the contact is not found.
	 */
	private function requireContact(string $uid): array {
		if ($this->contactsManager->isEnabled() === false) {
			throw new RuntimeException('Nextcloud Contacts is not available');
		}

		$ncContact = $this->findContactByUid(uid: $uid);

		if ($ncContact === null) {
			throw new RuntimeException('Contact not found in Nextcloud addressbook');
		}

		return $ncContact;
	}//end requireContact()

	/**
	 * Sync a Pipelinq client or contact to Nextcloud Contacts.
	 * Delegates to the ContactVcardService.
	 *
	 * @param string $objectType The object type (client or contact).
	 * @param string $objectId The object ID.
	 *
	 * @return ?string The contacts UID or null.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function syncToContacts(string $objectType, string $objectId): ?string {
		return $this->contactVcardService->syncToContacts(
			objectType: $objectType,
			objectId: $objectId
		);
	}//end syncToContacts()

	/**
	 * Build contact result entries from raw search results.
	 *
	 * @param array $results The raw contact search results.
	 * @param array $linkedUids The already-linked UIDs.
	 *
	 * @return array The formatted contact results.
	 */
	private function buildContactResults(array $results, array $linkedUids): array {
		$contacts = [];
		foreach ($results as $result) {
			if (($result['UID'] ?? null) === null) {
				continue;
			}

			$contacts[] = $this->formatContactResult(
				result: $result,
				linkedUids: $linkedUids
			);
		}

		return $contacts;
	}//end buildContactResults()

	/**
	 * Format a single contact result entry.
	 *
	 * @param array $result The raw contact result, with its UID.
	 * @param array $linkedUids The linked UIDs set.
	 *
	 * @return array The formatted contact entry.
	 */
	private function formatContactResult(array $result, array $linkedUids): array {
		$uid = (string)$result['UID'];
		return [
			'uid' => $uid,
			'name' => $this->extractFirstValue(value: ($result['FN'] ?? '')),
			'email' => $this->extractFirstValue(value: ($result['EMAIL'] ?? '')),
			'phone' => $this->extractFirstValue(value: ($result['TEL'] ?? '')),
			'org' => $this->extractFirstValue(value: ($result['ORG'] ?? '')),
			'addressBookKey' => $result['addressbook-key'] ?? '',
			'alreadyLinked' => in_array($uid, $linkedUids, true),
		];
	}//end formatContactResult()

	/**
	 * Find a Nextcloud contact by its UID.
	 *
	 * @param string $uid The contact UID to find.
	 *
	 * @return ?array The contact data or null if not found.
	 */
	private function findContactByUid(string $uid): ?array {
		// 'types' => true so multi-valued EMAIL/TEL/X-SOCIALPROFILE come back
		// as [{type, value}, ...] instead of a plain list of strings —
		// ContactDataBuilder needs the TYPE to build typed emails[]/phones[]/
		// socialProfiles[] entries on import.
		$results = $this->contactsManager->search($uid, ['UID'], ['limit' => 1, 'types' => true]);

		foreach ($results as $r) {
			if (($r['UID'] ?? '') === $uid) {
				return $r;
			}
		}

		return null;
	}//end findContactByUid()

	/**
	 * Whether a record's address book card exists, which address book holds
	 * it and when the card last changed (its vCard REV).
	 *
	 * @param string $uid The record's `contactsUid`.
	 *
	 * @return array{linked: bool, addressBook: string, updatedAt: string|null} The card's status.
	 *
	 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
	 */
	public function cardStatus(string $uid): array {
		$status = ['linked' => false, 'addressBook' => '', 'updatedAt' => null];
		if ($uid === '' || $this->contactsManager->isEnabled() === false) {
			return $status;
		}

		$card = $this->findContactByUid(uid: $uid);
		if ($card === null) {
			return $status;
		}

		$status['linked'] = true;
		$key = (string)($card['addressbook-key'] ?? '');
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if ((string)$book->getKey() === $key) {
				$status['addressBook'] = $book->getDisplayName();
				break;
			}
		}

		$status['updatedAt'] = $this->revisions->toIso(rev: $this->extractFirstValue(value: ($card['REV'] ?? '')));
		return $status;
	}//end cardStatus()
}//end class
