<?php

   
                                    
  
                                                                            
                                                                        
                                                                            
                                                                
                                                                             
                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                   
  
             
                                                                                 
  
                                                                                                                                                                                  
   
class MailTransportController extends Controller {
	   
                
   
                                          
                                                                                        
   
                                                                                 
                                                       
    
	public function __construct(
		IRequest $request,
		private DeliverabilityCheckService $deliverabilityCheckService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                            
   
                                                 
                                                                   
   
                                                                               
   
                                                                                                                                                                                   
   
                                                                           
                                                                              
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function checkDeliverability(string $id = '', bool $refresh = false): JSONResponse {
		$verdict = $this->deliverabilityCheckService->checkTransportById(id: $id, forceRefresh: $refresh);
		if ($verdict === null) {
			return new JSONResponse(['error' => 'mail-transport-not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($verdict);
	}                           
}           
