<?php

   
                                     
  
                                                                        
                                                               
  
                                                                            
                                                                        
                                                                             
                                                       
                                                                              
                                                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SocialAdvocacyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                               
  
                                                                                                                                                
   
class SocialAdvocacyController extends Controller {
	   
                
   
                                         
                                                                                            
                                                          
   
                
    
	public function __construct(
		IRequest $request,
		private readonly SocialAdvocacyService $advocacy,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                          
                                                    
   
                                      
   
                                                 
   
                                                                                                                                                 
    
	#[NoAdminRequired]
	public function share(string $id): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return $this->refuse();
		}

		                                                                     
		                                                                 
		return $this->answer(result: $this->advocacy->shareBundle(publicationId: $id, uid: $uid));
	}             

	   
                                                                           
   
                                      
   
                                                       
   
                                                                                                                                                 
    
	#[NoAdminRequired]
	public function confirmShare(string $id): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(
			result: $this->advocacy->confirmShare(
				publicationId: $id,
				uid: $uid,
				url: (string)$this->request->getParam('url', ''),
			),
		);
	}                    

	   
                                          
   
                                                             
   
                                      
    
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}              

	   
                       
   
                               
    
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}              
}           
