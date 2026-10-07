<?php

   
                                
  
                                                                   
                                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                          
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                                        
                                                             
  
                                                          
                                                     
   
class AnalyticsController extends Controller {
	   
                
   
                                              
                                                                        
                                                         
                                                                          
                                          
    
	public function __construct(
		IRequest $request,
		private AnalyticsService $analyticsService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                
   
                                                                      
                                                                         
                                        
   
                                                                    
   
                                                      
    
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                      
		                                                                    
		                                                             
		                                                            
		                                                                
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getOverview(period: $period));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] overview failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}                

	   
                                  
   
                                                                       
                                                                    
                                                                 
                                                     
   
                                                                               
   
                                                     
    
	#[NoAdminRequired]
	public function commercial(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                      
		                                                                    
		                                                             
		                                                            
		                                                                
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getCommercialOverview(period: $period));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] commercial failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}                  

	   
                              
   
                                                                         
                                                                
                                                                  
   
                                                                 
   
                                                      
    
	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                      
		                                                                    
		                                                             
		                                                            
		                                                                
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$metric = (string)$this->request->getParam('metric', '');
		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getTrends(metric: $metric, period: $period));
		} catch (InvalidArgumentException $e) {
			                                                                  
			                                                                  
			                                                                    
			                        
			$label = 'Unsupported metric';
			if ($e->getMessage() === 'Invalid period') {
				$label = 'Invalid period';
			}

			return new JSONResponse(['message' => $label], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] trends failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}              

	   
                               
   
                                                        
   
                                                                  
   
                                                      
    
	#[NoAdminRequired]
	public function funnels(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                                      
		                                                                    
		                                                             
		                                                            
		                                                                
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->analyticsService->getFunnels());
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] funnels failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}               
}           
