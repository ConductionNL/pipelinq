<?php

   
                                   
  
                                                                            
                                                                          
                                                                  
  
                                                                        
                                                                    
                                                                           
                                                                         
                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                               
  
                                                                                                                    
   
class SubscriptionController extends Controller {
	   
                
   
                                         
                                                                       
                                                              
                                                                      
                                                          
                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly SubscriptionService $subscriptions,
		private readonly SubscriptionQueryService $queries,
		private readonly PreferenceCentreService $preferences,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                         
   
                                                  
   
                                             
   
                                                                                                                      
    
	#[NoAdminRequired]
	public function forContact(string $contactId): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse([
			'subscriptions' => $this->queries->listSubscriptionsForContact(contactId: $contactId),
		]);
	}                  

	   
                                                                      
   
                                                                        
                                                                        
                                                                      
                              
   
                                                                      
   
                                                                                                                     
    
	#[NoAdminRequired]
	public function softOptIn(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$offered = $this->request->getParam('objectionOffered');
		$result = $this->subscriptions->importSoftOptIn(
			listId: (string)$this->request->getParam('listId', ''),
			contactId: (string)$this->request->getParam('contactId', ''),
			email: (string)$this->request->getParam('email', ''),
			evidence: [
				'objectionOffered' => ($offered === true || $offered === 'true' || $offered === '1'),
				'objectionOfferedAt' => (string)$this->request->getParam('objectionOfferedAt', ''),
				'objectionText' => (string)$this->request->getParam('objectionText', ''),
				'reference' => (string)$this->request->getParam('reference', ''),
			],
		);

		if ($result['status'] !== 'imported') {
			return new JSONResponse(
				['error' => ($result['error'] ?? 'The subscription could not be imported')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['status' => 'imported'], Http::STATUS_CREATED);
	}                 

	   
                                                                  
   
                                                                        
                                                                        
                                                                     
                        
   
                                                                   
   
                                                                                                           
    
	#[NoAdminRequired]
	public function unsubscribe(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$contactId = (string)$this->request->getParam('contactId', '');
		$listId = (string)$this->request->getParam('listId', '');
		$reason = (string)$this->request->getParam('reason', '');
		if ($contactId === '') {
			return new JSONResponse(['error' => 'Name the contact to unsubscribe'], Http::STATUS_BAD_REQUEST);
		}

		if ($listId === '') {
			$count = $this->subscriptions->globalUnsubscribe(contactId: $contactId, reason: $reason);
			return new JSONResponse(['count' => $count]);
		}

		$closed = $this->subscriptions->unsubscribeFromList(
			listId: $listId,
			contactId: $contactId,
			reason: $reason,
		);
		if ($closed === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['count' => 1]);
	}                   

	   
                                                                       
   
                                                                        
                                                                       
                                                           
   
                                                  
   
                                 
   
                                                                                                                      
    
	#[NoAdminRequired]
	public function preferenceLink(string $contactId): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		if ($contactId === '') {
			return new JSONResponse(['error' => 'Name the contact'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['url' => $this->preferences->preferencesUrlFor(contactId: $contactId)]);
	}                      

	   
                                                              
   
                                                                         
    
	private function requireCrmUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return null;
		}

		return $uid;
	}                      

	   
                                                                        
                                 
   
                               
    
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}              
}           
