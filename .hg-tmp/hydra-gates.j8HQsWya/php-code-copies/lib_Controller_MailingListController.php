<?php

   
                                  
  
                                                                
                                                               
                                                                         
                                                             
                                                                       
  
                                                                         
                                                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                     
  
                                                                                                                          
   
class MailingListController extends Controller {
	   
                
   
                                         
                                                          
                                                              
                                                          
                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly MailingListService $lists,
		private readonly SubscriptionQueryService $queries,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                            
   
                                         
                                
   
                                                
   
                                                                                                                           
    
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->lists->listMailingLists(page: $page, limit: $limit));
	}             

	   
                                                                        
   
                                               
   
                                                                   
   
                                                                                                                           
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$list = $this->lists->getMailingListById(listId: $id);
		if ($list === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			'list' => $list,
			'counts' => $this->queries->countsForList(listId: $id),
		]);
	}            

	   
                                            
   
                                                                        
   
                                                                                                                           
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->lists->createMailingList(payload: $this->collectBody(), createdByUid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['list' => ($result['list'] ?? null)], Http::STATUS_CREATED);
	}              

	   
                                                                    
   
                                               
   
                                                       
   
                                                                                                                           
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->lists->updateMailingList(listId: $id, patch: $this->collectBody());
		if (($result['error'] ?? '') === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['list' => ($result['list'] ?? null)]);
	}              

	   
                                                                
   
                                               
                                         
                                
   
                                                        
   
                                                                                                                           
    
	#[NoAdminRequired]
	public function subscriptions(string $id, int $page = 1, int $limit = 25): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$envelope = $this->queries->listSubscriptionsForList(listId: $id, page: $page, limit: $limit);
		$envelope['counts'] = $this->queries->countsForList(listId: $id);
		return new JSONResponse($envelope);
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

	   
                                                                        
   
                                                   
    
	private function collectBody(): array {
		$body = [];
		foreach (['name', 'description', 'optInMode', 'senderName', 'senderEmail', 'replyTo', 'footerAddress', 'status'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		$publicSignup = $this->request->getParam('publicSignup');
		if ($publicSignup !== null) {
			$body['publicSignup'] = ($publicSignup !== false && $publicSignup !== 'false' && $publicSignup !== '0');
		}

		return $body;
	}                   
}           
