<?php

   
                                 
  
                                                                   
                                                                   
                                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                    
                                    
  
                                               
                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                          
  
                                               
   
class RapportageController extends Controller {
	   
                
   
                                         
                                                                              
                                                      
                                                                                
    
	public function __construct(
		IRequest $request,
		private RapportageService $rapportageService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                                          
   
                                                                   
                                                                         
                                                              
   
                                               
   
                                                
                                                
    
	#[NoAdminRequired]
	public function getPipelineStats(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		                                                                 
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(data: ['message' => 'Forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$pipelineId = (string)$this->request->getParam('pipelineId', '');
		$dateFrom = (string)$this->request->getParam('dateFrom', '');
		$dateTo = (string)$this->request->getParam('dateTo', '');

		$pipelineIdArg = null;
		if ($pipelineId !== '') {
			$pipelineIdArg = $pipelineId;
		}

		$dateFromArg = null;
		if ($dateFrom !== '') {
			$dateFromArg = $dateFrom;
		}

		$dateToArg = null;
		if ($dateTo !== '') {
			$dateToArg = $dateTo;
		}

		try {
			$stageValues = $this->rapportageService->getStageValues(pipelineId: $pipelineIdArg);
			$sourcePerformance = $this->rapportageService->getSourcePerformance(
				dateFrom: $dateFromArg,
				dateTo: $dateToArg,
			);
			$agingBuckets = $this->rapportageService->getAgingBuckets();
			$winLoss = $this->rapportageService->getWinLossAnalysis(
				dateFrom: $dateFromArg,
				dateTo: $dateToArg,
			);
		} catch (\Throwable) {
			return new JSONResponse(data: ['message' => 'Operation failed'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			data: [
				'stageValues' => $stageValues,
				'sourcePerformance' => $sourcePerformance,
				'agingBuckets' => $agingBuckets,
				'winLoss' => $winLoss,
			]
		);

	}                        
}           
