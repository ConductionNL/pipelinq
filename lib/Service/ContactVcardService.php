<?php

/**
 * Pipelinq ContactVcardService.
 *
 * Service for syncing Pipelinq objects to Nextcloud Contacts as vCards.
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

use OCP\Contacts\IManager as IContactsManager;
use Psr\Log\LoggerInterface;

/**
 * Service for syncing Pipelinq objects to Nextcloud Contacts as vCards.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactVcardService {
	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The contacts manager.
	 * @param ContactVcardWriterService $writerService The vCard writer.
	 * @param ContactVcardPropertyBuilder $propBuilder The property builder.
	 * @param LoggerInterface $logger The logger.
	 * @param ContactObjectReader $objects Reads the client or contact to sync.
	 */
	public function __construct(
		private IContactsManager $contactsManager,
		private ContactVcardWriterService $writerService,
		private ContactVcardPropertyBuilder $propBuilder,
		private LoggerInterface $logger,
		private ContactObjectReader $objects,
	) {
	}//end __construct()

	/**
	 * Sync a Pipelinq client or contact to Nextcloud Contacts.
	 *
	 * @param string $objectType The object type (client or contact).
	 * @param string $objectId The object ID.
	 *
	 * @return ?string The contacts UID or null.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function syncToContacts(string $objectType, string $objectId): ?string {
		if ($this->contactsManager->isEnabled() === false) {
			$this->logger->debug('Pipelinq: Contacts sync skipped -- IManager not available');
			return null;
		}

		$objData = $this->objects->fetch(
			objectType: $objectType,
			objectId: $objectId
		);

		if ($objData === null) {
			return null;
		}

		$properties = $this->propBuilder->buildProperties(
			objData: $objData,
			objectType: $objectType
		);

		return $this->writerService->writeToAddressBook(
			properties: $properties,
			objData: $objData,
			objectType: $objectType
		);
	}//end syncToContacts()

	/**
	 * Provision (resolve or create) the Nextcloud addressbook contact for a NOT
	 * YET SAVED client/contact from its raw create-form fields, returning the
	 * contact UID plus the denormalised identity mirror to persist on the object.
	 *
	 * This is the contact-FIRST half of the client-contact unification: the
	 * `client`/`contact` schema marks `contactsUid` REQUIRED, and the Nextcloud
	 * Contact is authoritative (never minted locally), so the contact must exist
	 * before the object is saved. It mirrors the repair-step resolution order —
	 * match an EXISTING contact (email first, then ORG for organisations) and
	 * only create a fresh vCard when nothing matches — reusing the SAME property
	 * builder + writer the post-save sync uses, so a manual create and the
	 * migration converge on identical identities.
	 *
	 * Returns null only when Contacts is unavailable or the write failed; the
	 * caller then surfaces a clean error instead of a raw 400.
	 *
	 * @param array $form The raw create-form fields (name/type/email/phone/...).
	 * @param string $objectType The object type ('client' or 'contact').
	 *
	 * @return ?array{contactsUid:string,name:string,email:string,phone:string} The
	 *                                                                          resolved UID + identity mirror, or null on failure.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function provisionContactFromForm(array $form, string $objectType): ?array {
		if (in_array($objectType, ContactObjectReader::OBJECT_TYPES, true) === false) {
			$this->logger->warning('ContactVcardService: invalid objectType for provisioning', ['objectType' => $objectType]);
			return null;
		}

		if ($this->contactsManager->isEnabled() === false) {
			$this->logger->warning('Pipelinq: cannot provision contact -- Contacts (IManager) not available');
			return null;
		}

		// 1) Try to match an EXISTING contact (email first, then ORG for orgs) so
		// we never create a duplicate identity when one already exists.
		$contactsUid = $this->matchExistingContact(form: $form);

		// 2) No match -> create a fresh vCard from the form fields via the SAME
		// builder + writer the post-save sync uses (no new vCard engine).
		if ($contactsUid === null) {
			$properties = $this->propBuilder->buildProperties(
				objData: $form,
				objectType: $objectType
			);

			$contactsUid = $this->writerService->writeVcard(properties: $properties);
		}

		if ($contactsUid === null || $contactsUid === '') {
			return null;
		}

		return [
			'contactsUid' => $contactsUid,
			'name' => (string)($form['name'] ?? ''),
			'email' => (string)($form['email'] ?? ''),
			'phone' => (string)($form['phone'] ?? ''),
		];
	}//end provisionContactFromForm()

	/**
	 * Match an existing Nextcloud Contact for a create form by email first, then
	 * by ORG name for organisations. Returns the matched UID or null.
	 *
	 * Same resolution order as the UnifyClientContactIdentity repair step, so a
	 * UI create and the migration converge on the same contact.
	 *
	 * @param array $form The raw create-form fields.
	 *
	 * @return ?string The matched contactsUid, or null when none matches.
	 */
	private function matchExistingContact(array $form): ?string {
		$searchTerms = [];

		$email = (string)($form['email'] ?? '');
		if ($email !== '') {
			$searchTerms[] = ['term' => $email, 'props' => ['EMAIL', 'UID']];
		}

		if ((string)($form['type'] ?? '') === 'organization') {
			$name = (string)($form['name'] ?? '');
			if ($name !== '') {
				$searchTerms[] = ['term' => $name, 'props' => ['ORG', 'UID']];
			}
		}

		foreach ($searchTerms as $search) {
			try {
				$results = $this->contactsManager->search($search['term'], $search['props'], ['limit' => 1]);
			} catch (\Exception $e) {
				$this->logger->warning(
					'Pipelinq: contact match search failed',
					['term' => $search['term'], 'exception' => $e->getMessage()]
				);
				continue;
			}

			foreach ($results as $hit) {
				$uid = (string)($hit['UID'] ?? '');
				if ($uid !== '') {
					return $uid;
				}
			}
		}

		return null;
	}//end matchExistingContact()
}//end class
