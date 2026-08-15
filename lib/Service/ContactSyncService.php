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

use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for searching and importing Nextcloud contacts into Pipelinq.
 */
class ContactSyncService {
	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The contacts manager.
	 * @param ContactImportService $contactImportService The contact import service.
	 * @param ContactVcardService $contactVcardService The vCard sync service.
	 * @param ContactLinkedUidsService $linkedUidsService The linked UIDs service.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ContainerInterface $container The service container.
	 */
	public function __construct(
		private IContactsManager $contactsManager,
		private ContactImportService $contactImportService,
		private ContactVcardService $contactVcardService,
		private ContactLinkedUidsService $linkedUidsService,
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential validation + contact-provisioning guard clauses; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential validation + contact-provisioning guard clauses; extraction adds no clarity.
	 */
	public function createWithContact(string $objectType, array $form): array {
		if (in_array($objectType, ['client', 'contact'], true) === false) {
			throw new RuntimeException('Invalid objectType -- must be client or contact');
		}

		if (trim((string)($form['name'] ?? '')) === '') {
			throw new RuntimeException('Name is required');
		}

		$provision = $this->contactVcardService->provisionContactFromForm(
			form: $form,
			objectType: $objectType
		);

		if ($provision === null) {
			throw new RuntimeException('Could not provision the Nextcloud contact -- is the Contacts app enabled?');
		}

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

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Pipelinq register or schema is not configured');
		}

		$created = $this->getObjectService()->saveObject(
			$payload,
			[],
			$registerId,
			$schemaId,
			null
		);

		if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {
			return $created->jsonSerialize();
		}

		if (is_array($created) === true) {
			return $created;
		}

		return [];
	}//end createWithContact()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()

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
		if ($this->contactsManager->isEnabled() === false) {
			throw new RuntimeException('Nextcloud Contacts is not available');
		}

		$ncContact = $this->findContactByUid(uid: $uid);

		if ($ncContact === null) {
			throw new RuntimeException('Contact not found in Nextcloud addressbook');
		}

		if ($type === 'client') {
			return $this->contactImportService->importAsClient(
				ncContact: $ncContact,
				uid: $uid
			);
		}

		return $this->contactImportService->importAsContact(
			ncContact: $ncContact,
			uid: $uid,
			clientId: $clientId
		);
	}//end importContact()

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
			$uid = $result['UID'] ?? null;
			if ($uid === null) {
				continue;
			}

			$contacts[] = $this->formatContactResult(
				result: $result,
				uid: $uid,
				linkedUids: $linkedUids
			);
		}

		return $contacts;
	}//end buildContactResults()

	/**
	 * Format a single contact result entry.
	 *
	 * @param array $result The raw contact result.
	 * @param string $uid The contact UID.
	 * @param array $linkedUids The linked UIDs set.
	 *
	 * @return array The formatted contact entry.
	 */
	private function formatContactResult(array $result, string $uid, array $linkedUids): array {
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
		$results = $this->contactsManager->search($uid, ['UID'], ['limit' => 1]);

		foreach ($results as $r) {
			if (($r['UID'] ?? '') === $uid) {
				return $r;
			}
		}

		return null;
	}//end findContactByUid()

	/**
	 * Extract first value from a vCard property that may be an array or string.
	 *
	 * @param mixed $value The value to extract from.
	 *
	 * @return string The extracted string value.
	 */
	private function extractFirstValue(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value[0] ?? '');
		}

		return (string)$value;
	}//end extractFirstValue()
}//end class
