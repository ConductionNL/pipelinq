<?php

   
                                   
  
                                                                         
                             
  
                                                                      
                                                                             
                                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SatisfactionAggregationService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                                           
  
                                                                                                                                   
   
class SatisfactionController extends Controller {
	   
                
   
                                         
                                                                            
                                                                    
    
	public function __construct(
		IRequest $request,
		private readonly SatisfactionAggregationService $aggregation,
		private readonly SurveyDispatchService $dispatchService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                    
   
                                              
   
                                                         
   
                                                                                                                                 
    
	#[NoAdminRequired]
	public function clientPanel(string $clientId): JSONResponse {
		return new JSONResponse($this->aggregation->forClient(clientId: $clientId), 200);
	}                   

	   
                                                                    
   
                                                                      
   
                                     
   
                                                                                                                                    
    
	#[NoAdminRequired]
	public function responseRate(string $surveyId = ''): JSONResponse {
		$filters = [];
		if (trim($surveyId) !== '') {
			$filters = ['surveyRef' => trim($surveyId)];
		}

		return new JSONResponse(
			$this->dispatchService->responseRate(
				invitations: $this->dispatchService->read(filters: $filters)
			),
			200
		);
	}                    
}           
