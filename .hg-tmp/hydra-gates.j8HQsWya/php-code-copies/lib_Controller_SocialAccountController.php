<?php

   
                                    
  
                                                                           
                                                                     
                                                                           
                                                            
                                                                          
                                                                            
               
  
                                                                             
                                                                         
                                                                           
                                                                           
                                                                                 
                                                                             
                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\SocialAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                       
  
                                                                                                                                          
   
class SocialAccountController extends Controller {
	   
                
   
                                         
                                                                
                                                          
                                                                                        
                                                                                                                         
   
                
    
	public function __construct(
		IRequest $request,
		private readonly SocialAccountService $accounts,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly ConnectionReportService $connectionReports,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                            
   
                                                                           
                                                                         
                          
   
                                                 
   
                                                                                                                                                  
                                                                                                                                                     
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->accounts->listAccounts();
		$this->connectionReports->reportSocialReadiness(readiness: $result['readiness']);

		return new JSONResponse($result);
	}             

	   
                                                
   
                                               
   
                                                 
   
                                                                                                                                                
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$account = $this->accounts->getAccount(accountId: $id);
		if ($account === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return $this->refuse();
		}

		return new JSONResponse(['account' => $account]);
	}            

	   
                                                                       
   
                                                                         
   
                                                                                                                                                
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->accounts->createAccount(payload: $this->collectBody(), uid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}              

	   
                                                                             
                                                    
   
                                                                           
                                                                         
   
                                  
   
                                                                          
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function connect(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->accounts->connectRequest(
			accountId: $id,
			uid: $uid,
			returnUrl: $this->returnUrl(accountId: $id),
		);

		return $this->answer(result: $result);
	}               

	   
                                                                             
                        
   
                                  
   
                                                   
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function attach(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		                                                                        
		$result = $this->accounts->attachCredential(
			accountId: $id,
			uid: $uid,
			payload: ['credentialRef' => (string)$this->request->getParam('credentialRef', '')],
		);

		return $this->answer(result: $result);
	}              

	   
                                                                                 
   
                                  
   
                                                   
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function revoke(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->accounts->revoke(accountId: $id, uid: $uid));
	}              

	   
                                                                             
   
                                  
   
                                                 
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function sync(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$account = $this->accounts->getAccount(accountId: $id);
		if ($account === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return $this->refuse();
		}

		return new JSONResponse(['account' => $this->accounts->syncStatus(accountId: $id)]);
	}            

	   
                                          
   
                                                             
   
                                      
    
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}              

	   
                                                                    
                                                                          
   
                                                         
   
                                   
    
	private function returnUrl(string $accountId): string {
		return '/apps/' . Application::APP_ID . '/social-accounts/' . rawurlencode($accountId);
	}                 

	   
                                                            
   
                                             
    
	private function requireUser(): ?string {
		return $this->userSession->getUser()?->getUID();
	}                   

	   
                                                                  
   
                                             
    
	private function requireCrmUser(): ?string {
		$uid = $this->requireUser();
		if ($uid === null || $this->policy->isPrivileged(uid: $uid) === false) {
			return null;
		}

		return $uid;
	}                      

	   
                                                                        
                                 
   
                               
    
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}              

	   
                                         
   
                                                                           
                                                                            
                                    
   
                                                       
    
	private function collectBody(): array {
		$body = [];
		foreach (SocialAccountService::CLIENT_WRITABLE as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		return $body;
	}                   
}           
