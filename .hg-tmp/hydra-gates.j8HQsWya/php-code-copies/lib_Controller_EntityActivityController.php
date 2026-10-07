<?php

   
                                     
  
                                                                        
                                                                    
  
                                               
                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EntityActivityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                       
  
                                                                     
                                                                        
                                                                 
                                                                          
                                                                         
                           
  
                                                      
   
class EntityActivityController extends Controller {
	   
                
   
                                              
                                                                      
                                                              
                                              
    
	public function __construct(
		IRequest $request,
		private readonly EntityActivityService $service,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                           
   
                                                                      
                                                        
                                                        
   
                                             
                                                              
                                               
   
                                                                       
   
                                                       
    
	#[NoAdminRequired]
	public function index(string $entityType, string $entityId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->service->isAllowedEntityType(entityType: $entityType) === false) {
			return new JSONResponse(
				['message' => 'Invalid entity type'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$type = (string)$this->request->getParam('type', 'all');
		$page = (int)$this->request->getParam('_page', 1);
		$limit = (int)$this->request->getParam('_limit', 20);

		try {
			$result = $this->service->getActivity(
				entityType: $entityType,
				entityId: $entityId,
				type: $type,
				page: $page,
				limit: $limit
			);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			                                                              
			                                                               
			                                                           
			                                                          
			                                                           
			                                              
			$this->logRejectedRequest(exception: $e);
			return new JSONResponse(
				['message' => 'Invalid entity type'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logUnexpectedFailure(exception: $e);
			return new JSONResponse(
				['message' => 'Failed to load activity'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}             

	   
                                                                         
   
                                                                    
                                                                   
                                                                  
   
                                                     
   
                
    
	private function logRejectedRequest(Throwable $exception): void {
		$this->logger->info(
			'EntityActivityController: rejected request',
			['exception' => $exception]
		);
	}                          

	   
                                                     
   
                                                                       
                                                            
   
                                                     
   
                
    
	private function logUnexpectedFailure(Throwable $exception): void {
		$this->logger->error(
			'EntityActivityController: failed to load activity',
			['exception' => $exception]
		);
	}                            
}           
