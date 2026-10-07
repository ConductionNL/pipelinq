<?php

   
                                       
  
                                                                                 
                                                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\LoyaltyReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                     
  
                                                                                 
                                                                             
                                                             
  
                                                              
   
class LoyaltyReportingController extends Controller {
	   
                
   
                                         
                                                                           
                                                      
                                                                          
    
	public function __construct(
		IRequest $request,
		private LoyaltyReportingService $reportingService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                             
   
                                                  
                                                   
                                                 
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function kpis(string $programmeId, ?string $from = null, ?string $to = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                       
		                                                   
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			$this->reportingService->getKpis(programmeId: $programmeId, from: $from, to: $to)
		);
	}            

	   
                                              
   
                                                  
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function liability(string $programmeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                       
		                                                   
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->reportingService->getLiabilitySnapshot(programmeId: $programmeId));
	}                 

	   
                          
   
                                                  
   
                        
   
                                                                        
                                                                    
                                                                       
                                                                         
                                                    
                                                          
    
	#[NoAdminRequired]
	public function tierDistribution(string $programmeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                       
		                                                   
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['tiers' => $this->reportingService->getTierReport(programmeId: $programmeId)]);
	}                        

	   
                        
   
                                                  
                                                 
   
                        
   
                                                                         
                                                                            
                                                                          
                                                                          
                                                                            
                                                                        
                                                                           
                                                                       
                                                                             
    
	#[NoAdminRequired]
	public function expiryForecast(string $programmeId, int $days = 30): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                       
		                                                   
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			$this->reportingService->getExpiryForecast(programmeId: $programmeId, days: $days)
		);
	}                      
}           
