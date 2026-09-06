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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContactSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
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
	 * @param ContactSyncService $contactSyncService The contact sync service.
	 * @param IUserSession $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ContactSyncService $contactSyncService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$query = $this->request->getParam('q', '');
		if (trim($query) === '') {
			return new JSONResponse(['results' => []]);
		}

		try {
			$results = $this->contactSyncService->searchContacts($query);
			return new JSONResponse(['results' => $results]);
		} catch (\Exception $e) {
			$this->logger->error('ContactSyncController::search failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				500
			);
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
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
			$this->logger->error('ContactSyncController::import failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				500
			);
		}//end try
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$objectType = $this->request->getParam('objectType', 'client');
		$object = $this->request->getParam('object', []);

		if (in_array($objectType, ['client', 'contact'], true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid objectType -- must be client or contact')], 400);
		}

		if (is_array($object) === false || trim((string)($object['name'] ?? '')) === '') {
			return new JSONResponse(['error' => $this->l10n->t('Name is required')], 400);
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
			$this->logger->error('ContactSyncController::create failed', ['exception' => $e]);
			return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		// Contact sync reaches address books and external directories — a CRM
		// capability, not an any-authenticated-user one. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$objectType = $this->request->getParam('objectType', '');
		$objectId = $this->request->getParam('objectId', '');

		if ($objectType === '' || $objectId === '') {
			return new JSONResponse(['error' => $this->l10n->t('Missing objectType or objectId')], 400);
		}

		if (in_array($objectType, ['client', 'contact'], true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid objectType -- must be client or contact')], 400);
		}

		try {
			$contactsUid = $this->contactSyncService->syncToContacts(
				objectType: $objectType,
				objectId: $objectId
			);

			// 🔴 null MEANS THE SYNC DID NOT HAPPEN.
			//
			// syncToContacts() answers null when the Contacts app is
			// unavailable, the object was not found, or the vCard write failed.
			// This used to answer 200 {success: true, contactsUid: null} over
			// every one of those, so a caller was told the write-back succeeded
			// while nothing had been written to the addressbook at all.
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
		} catch (\Exception $e) {
			$this->logger->error('ContactSyncController::writeBack failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				500
			);
		}
	}//end writeBack()
}//end class
