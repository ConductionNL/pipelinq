<?php

   
                                
  
                                                          
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;

   
                                         
  
                                                                          
   
class DashboardController extends Controller {
	   
                
   
                                         
                                                                                        
                                                       
                                                                               
    
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IAppConfig $appConfig,
		private readonly MenuStructure $menuStructure,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                   
   
                                                                     
                                                                        
                                                            
   
                                                   
   
                    
                   
                                                                                              
                                                                                                      
    
	public function page(): TemplateResponse {
		$this->initialState->provideInitialState(
			MenuStructure::KEY,
			$this->menuStructure->normalise(
				stored: $this->appConfig->getValueString(Application::APP_ID, MenuStructure::KEY, '')
			)
		);
		$this->initialState->provideInitialState(
			MenuStructure::MODULES_KEY,
			$this->menuStructure->normaliseModules(
				stored: $this->appConfig->getValueString(Application::APP_ID, MenuStructure::MODULES_KEY, '')
			)
		);

		return new TemplateResponse(Application::APP_ID, 'index');
	}            
}           
