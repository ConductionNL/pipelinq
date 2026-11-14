<?php

/**
 * Pipelinq ContactSyncController.
 *
 * Controller for synchronizing contacts between Nextcloud Contacts and Pipelinq.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactSyncServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactSyncSettingsServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for contact synchronization.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-write-back-sync-mvp
 */
class ContactSyncController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContactSyncServiceInterface $contactSyncService The contact sync service.
	 * @param ContactSyncSettingsServiceInterface $syncSettings The user's write-back switch.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ContactSyncServiceInterface $contactSyncService,
		private ContactSyncSettingsServiceInterface $syncSettings,
		private CrmPrivilegeCheck $privilege,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A record's address book card: linked or not, its address book and when
	 * it last changed. Query param `uid`, the record's `contactsUid`.
	 *
	 * @return JSONResponse The card's status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
	 */
	public function status(): JSONResponse {
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = trim((string)$this->request->getParam('uid', ''));
		try {
			return new JSONResponse($this->contactSyncService->cardStatus(uid: $uid));
		} catch (\Exception $e) {
			return $this->unexpectedError(action: 'status', failure: $e);
		}
	}//end status()

	/**
	 * Search Nextcloud addressbooks for contacts.
	 *
	 * @return JSONResponse The search results.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function search(): JSONResponse {
		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$query = $this->request->getParam('q', '');
		if (trim($query) === '') {
			return new JSONResponse(['results' => []]);
		}

		try {
			$results = $this->contactSyncService->searchContacts($query);
			return new JSONResponse(['results' => $results]);
		} catch (\Exception $e) {
			return $this->unexpectedError(action: 'search', failure: $e);
		}
	}//end search()

	/**
	 * Import a Nextcloud contact into Pipelinq.
	 *
	 * @return JSONResponse The import result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function import(): JSONResponse {
		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->request->getParam('uid', '');
		$addressBookKey = $this->request->getParam('addressBookKey', '');
		$type = $this->request->getParam('type', 'client');
		$clientId = $this->request->getParam('clientId');

		if ($uid === '') {
			return new JSONResponse(['error' => $this->l10n->t('Missing uid parameter')], 400);
		}

		try {
			$created = $this->contactSyncService->importContact(
				uid: $uid,
				addressBookKey: $addressBookKey,
				type: $type,
				clientId: $clientId
			);
			return new JSONResponse(
				[
					'success' => true,
					'object' => $created,
				]
			);
		} catch (\Exception $e) {
			return $this->unexpectedError(action: 'import', failure: $e);
		}
	}//end import()

	/**
	 * Contact-FIRST create of a client/contact: provision the authoritative
	 * Nextcloud contact from the form fields, then save the object with the
	 * required `contactsUid`. Returns 201 with the created object.
	 *
	 * This is the create path the bespoke ClientForm + the generic add flow post
	 * to, so a client is always backed by a real NC contact and the required
	 * `contactsUid` is never missing (client-contact unification).
	 *
	 * @return JSONResponse The created object (201) or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function create(): JSONResponse {
		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$objectType = $this->request->getParam('objectType', 'client');
		$object = $this->request->getParam('object', []);

		$refusal = $this->createRefusal(objectType: $objectType, object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$created = $this->contactSyncService->createWithContact(
				objectType: $objectType,
				form: $object
			);
			return new JSONResponse(['success' => true, 'object' => $created], Http::STATUS_CREATED);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		} catch (\Exception $e) {
			return $this->unexpectedError(action: 'create', failure: $e);
		}
	}//end create()

	/**
	 * Sync a Pipelinq object to Nextcloud Contacts (write-back).
	 *
	 * @return JSONResponse The sync result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function writeBack(): JSONResponse {
		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		// A user who turned write-back off keeps the addressbook as it is.
		if ($this->syncSettings->writesBack(userId: $uid) === false) {
			return new JSONResponse(['success' => true, 'skipped' => true]);
		}

		$objectType = $this->request->getParam('objectType', '');
		$objectId = $this->request->getParam('objectId', '');
		$refusal = $this->objectRefRefusal(objectType: $objectType, objectId: $objectId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->writeBackAnswer(
				contactsUid: $this->contactSyncService->syncToContacts(
					objectType: $objectType,
					objectId: $objectId
				)
			);
		} catch (\Exception $e) {
			return $this->unexpectedError(action: 'writeBack', failure: $e);
		}
	}//end writeBack()

	/**
	 * The 400 for a create request that is not a named client or contact, or null.
	 *
	 * @param mixed $objectType The object type parameter.
	 * @param mixed $object     The form fields parameter.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function createRefusal(mixed $objectType, mixed $object): ?JSONResponse {
		if (in_array($objectType, ['client', 'contact'], true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid objectType -- must be client or contact')], 400);
		}

		if (is_array($object) === false || trim((string)($object['name'] ?? '')) === '') {
			return new JSONResponse(['error' => $this->l10n->t('Name is required')], 400);
		}

		return null;
	}//end createRefusal()

	/**
	 * The 400 for a write-back request without a client or contact reference, or null.
	 *
	 * @param mixed $objectType The object type parameter.
	 * @param mixed $objectId   The object id parameter.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function objectRefRefusal(mixed $objectType, mixed $objectId): ?JSONResponse {
		if ($objectType === '' || $objectId === '') {
			return new JSONResponse(['error' => $this->l10n->t('Missing objectType or objectId')], 400);
		}

		if (in_array($objectType, ['client', 'contact'], true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid objectType -- must be client or contact')], 400);
		}

		return null;
	}//end objectRefRefusal()

	/**
	 * The answer to a write-back.
	 *
	 * 🔴 null MEANS THE SYNC DID NOT HAPPEN.
	 *
	 * syncToContacts() answers null when the Contacts app is
	 * unavailable, the object was not found, or the vCard write failed.
	 * This used to answer 200 {success: true, contactsUid: null} over
	 * every one of those, so a caller was told the write-back succeeded
	 * while nothing had been written to the addressbook at all.
	 *
	 * @param string|null $contactsUid The synced contact's uid, or null.
	 *
	 * @return JSONResponse The answer.
	 */
	private function writeBackAnswer(?string $contactsUid): JSONResponse {
		if ($contactsUid === null) {
			return new JSONResponse(
				[
					'success' => false,
					'error' => $this->l10n->t('Write-back to Contacts did not complete'),
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			[
				'success' => true,
				'contactsUid' => $contactsUid,
			]
		);
	}//end writeBackAnswer()

	/**
	 * Log an action's unexpected failure and answer the generic 500.
	 *
	 * @param string     $action  The action name.
	 * @param \Exception $failure The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	private function unexpectedError(string $action, \Exception $failure): JSONResponse {
		$this->logger->error('ContactSyncController::' . $action . ' failed', ['exception' => $failure]);
		return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
	}//end unexpectedError()
}//end class
