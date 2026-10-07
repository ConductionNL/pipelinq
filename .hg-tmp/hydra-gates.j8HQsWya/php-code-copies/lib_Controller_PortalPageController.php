<?php

   
                                 
  
                                                                             
                                                                             
                                                                              
                                                                              
                                                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

   
                                      
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalPageController extends Controller {
	   
                
   
                                         
    
	public function __construct(IRequest $request) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                       
   
                                             
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'portal',
			[],
			TemplateResponse::RENDER_AS_PUBLIC
		);

		                                                                       
		                                                                     
		                                                     
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameAncestorDomain('*');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}             

	   
                                                                            
                                                      
   
                                                                          
                                                                             
                                           
   
                                                                         
                                                            
   
                                             
   
                    
                   
               
   
                                                                         
                                                                             
                                           
   
                                                                           
                                                                
   
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 240, period: 60)]
	public function subpath(string $path = ''): TemplateResponse {
		return $this->index();
	}               
}           
