<?php

   
                                   
  
                                                                            
                                                                             
                                                                              
                                                             
  
                                                                             
                                                                          
                                                                              
                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SurveyResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                                        
  
                                                                                                                                                    
   
class PublicSurveyController extends Controller {
	   
                
   
                                         
                                                                        
    
	public function __construct(
		IRequest $request,
		private readonly SurveyResponseService $responses,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                              
   
                                                  
   
                                                                  
   
                                                                                                                                                     
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function showInvitation(string $token): JSONResponse {
		return $this->respond(result: $this->responses->show(token: $token));
	}                      

	   
                                    
   
                                                  
                                                                            
                                                                           
                                                                            
                                                                       
                                 
   
                                                                    
   
                                                                                                                                                     
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function submitInvitation(string $token, array $answers = [], ?bool $optOut = null): JSONResponse {
		return $this->respond(
			result: $this->responses->submit(token: $token, answers: $answers, optOut: $optOut)
		);
	}                        

	   
                                               
   
                                                             
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
