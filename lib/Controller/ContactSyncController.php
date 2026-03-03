<?php

/**
 * Pipelinq ContactSyncController.
 *
 * Controller for synchronizing contacts between Nextcloud Contacts and Pipelinq.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for contact synchronization.
 */
class ContactSyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request.
     * @param ContactSyncService $contactSyncService The contact sync service.
     */
    public function __construct(
        IRequest $request,
        private ContactSyncService $contactSyncService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * Search Nextcloud addressbooks for contacts.
     *
     * @return JSONResponse The search results.
     *
     * @NoAdminRequired
     */
    public function search(): JSONResponse
    {
        $query = $this->request->getParam('q', '');
        if (trim($query) === '') {
            return new JSONResponse(['results' => []]);
        }

        try {
            $results = $this->contactSyncService->searchContacts($query);
            return new JSONResponse(['results' => $results]);
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
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
     */
    public function import(): JSONResponse
    {
        $uid            = $this->request->getParam('uid', '');
        $addressBookKey = $this->request->getParam('addressBookKey', '');
        $type           = $this->request->getParam('type', 'client');
        $clientId       = $this->request->getParam('clientId');

        if ($uid === '') {
            return new JSONResponse(['error' => 'Missing uid parameter'], 400);
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
                        'object'  => $created,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end import()

    /**
     * Sync a Pipelinq object to Nextcloud Contacts (write-back).
     *
     * @return JSONResponse The sync result.
     *
     * @NoAdminRequired
     */
    public function writeBack(): JSONResponse
    {
        $objectType = $this->request->getParam('objectType', '');
        $objectId   = $this->request->getParam('objectId', '');

        if ($objectType === '' || $objectId === '') {
            return new JSONResponse(['error' => 'Missing objectType or objectId'], 400);
        }

        if (in_array($objectType, ['client', 'contact'], true) === false) {
            return new JSONResponse(['error' => 'Invalid objectType -- must be client or contact'], 400);
        }

        try {
            $contactsUid = $this->contactSyncService->syncToContacts(
                objectType: $objectType,
                objectId: $objectId
            );
            return new JSONResponse(
                    [
                        'success'     => true,
                        'contactsUid' => $contactsUid,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => $e->getMessage(),
                    ],
                    500
                    );
        }
    }//end writeBack()
}//end class
