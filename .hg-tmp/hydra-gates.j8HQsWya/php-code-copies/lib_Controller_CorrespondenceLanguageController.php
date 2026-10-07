<?php

   
                                             
  
                                                                              
                                                                            
                                                                             
                                                                             
                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CorrespondenceLanguageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                                           
  
                                                                                                                                                           
   
class CorrespondenceLanguageController extends Controller {
	   
                
   
                                         
                                                               
    
	public function __construct(
		IRequest $request,
		private readonly CorrespondenceLanguageService $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                       
   
                                              
   
                                                                                                                                                                        
    
	#[NoAdminRequired]
	public function available(): JSONResponse {
		return new JSONResponse(
			[
				'languages' => $this->service->available(),
				'instanceDefault' => $this->service->instanceDefault(),
			],
			200
		);
	}                 

	   
                                                                       
   
                                                   
   
                                                    
   
                                                                                                                                                            
    
	#[NoAdminRequired]
	public function resolve(string $partyId): JSONResponse {
		return $this->respond(result: $this->service->resolve(partyId: $partyId));
	}               

	   
                                             
   
                                                   
                                                              
   
                                                                       
   
                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function setPreference(string $partyId, string $language = ''): JSONResponse {
		return $this->respond(
			result: $this->service->setPreference(partyId: $partyId, tag: $language)
		);
	}                     

	   
                                               
   
                                                             
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
