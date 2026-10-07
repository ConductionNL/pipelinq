<?php

   
                                     
  
                                                                               
                                                                          
                                                                           
        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                 
  
                                                                                                           
   
class MessagingAdminController extends Controller {
	   
                
   
                                                               
                                                                                     
                                                              
                                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly MessagingServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                    
   
                                                                                                                        
   
                                                                   
                                                                                                            
    
	public function getServiceAccount(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->body(status: $this->serviceAccount->status()), Http::STATUS_OK);
	}                         

	   
                                                                            
                                      
   
                                                                                                                           
   
                                                                                     
                                                                                                            
    
	public function saveServiceAccount(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$status = $this->serviceAccount->assign(userId: (string)$this->request->getParam('userId', ''));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['errorCode' => 'badRequest', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->body(status: $status), Http::STATUS_OK);
	}                          

	   
                                            
   
                                   
    
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}               

	   
                                                     
   
                                                                                       
   
                                                   
    
	private function body(array $status): array {
		return array_merge($status, ['group' => MessagingServiceAccount::GROUP]);
	}            
}           
