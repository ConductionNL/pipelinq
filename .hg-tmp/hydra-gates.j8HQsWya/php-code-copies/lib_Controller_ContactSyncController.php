<?php

   
                                  
  
                                                                                 
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                             
                                             
   

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

   
                                          
  
                                                                             
   
class ContactSyncController extends Controller {
	   
                
   
                                         
                                                                           
                                                      
                                                                          
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ContactSyncService $contactSyncService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                               
   
                                            
   
                    
   
                                              
    
	public function search(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                
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
	}              

	   
                                             
   
                                           
   
                    
   
                                              
    
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                
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
		}         
	}              

	   
                                                                         
                                                                         
                                                                
   
                                                                              
                                                                          
                                                                
   
                                                              
   
                    
   
                                                     
    
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                
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
	}              

	   
                                                              
   
                                         
   
                    
   
                                              
    
	public function writeBack(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                
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
	}                 
}           
