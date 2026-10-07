<?php

   
                                       
  
                                                                          
  
                                    
                                               
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                           
                                                                                                        
                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                
  
                                                                         
                                                                            
                                
  
                                                                                                    
   
class ActivityTimelineController extends Controller {
	   
                
   
                                         
                                                                          
                                                      
                                              
                                                          
                                                                          
    
	public function __construct(
		IRequest $request,
		private ActivityTimelineService $service,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private ObjectOwnerAccessPolicy $policy,
	) {
		                                                                    
		                                                                    
		                                                                  
		           
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                  
   
                                                                         
                                   
   
                                                                             
                                                                             
                                                                          
                                                                        
                                                                           
                                                                        
                                                     
   
                                               
                                                               
   
                                                      
    
	private function objectAccessible(string $entityId, string $userId): bool {
		                                                                         
		                                                                   
		                                                                    
		                                                                       
		                                                                      
		                                                                          
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find($entityId, []);
			if ($object === null) {
				return false;
			}

			                                  
			  
			                                                                   
			                                                                 
			                                                               
			                                                                 
			                                                            
			                                            
			  
			                                                                  
			                                                                   
			                                                                   
			                    
			                                                                   
			                                                                
			                                                                 
			                                                    
			                                                                
			                                                            
			                                                                    
			                                                                  
			                                                                  
			                                                                
			                                                              
			         
			$payload = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$payload = $object->jsonSerialize();
			}

			                                                                      
			                                                                      
			                                                                    
			                                                                   
			                                                    
			$attributes = [];
			if (is_array($payload) === true) {
				$attributes = $payload;
			}

			return $this->policy->mayAccess(
				uid: $userId,
				object: $attributes,
				ownerField: 'ownerId'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ActivityTimelineController: could not verify object access, denying',
				['entityId' => $entityId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}                        

	   
                                                      
   
                                                                              
                     
   
                                                                  
   
                    
   
                                                                                                         
    
	public function getTimeline(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');

		if ($entityType === '' || $entityId === '') {
			return new JSONResponse(
				['message' => 'entityType and entityId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                                                                      
		                                                                 
		                                                                       
		                
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$params = [
			'from' => $this->request->getParam('from'),
			'to' => $this->request->getParam('to'),
			'types' => $this->request->getParam('types'),
			'_page' => $this->request->getParam('_page'),
			'_limit' => $this->request->getParam('_limit'),
		];

		try {
			$result = $this->service->getTimeline(
				entityType: $entityType,
				entityId: $entityId,
				params: $params
			);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to load timeline',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to load timeline'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                   

	   
                                                                                
   
                                                                    
   
                    
   
                                                                                                   
    
	public function getWorklog(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');

		if ($entityType === '' || $entityId === '') {
			return new JSONResponse(
				['message' => 'entityType and entityId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                                                                      
		                                                                 
		                                                                       
		                
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$params = [
			'_page' => $this->request->getParam('_page'),
			'_limit' => $this->request->getParam('_limit'),
		];

		try {
			$result = $this->service->getWorklog(
				entityType: $entityType,
				entityId: $entityId,
				params: $params
			);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to load worklog',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to load worklog'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                  

	   
                                         
   
                                                                  
   
                    
   
                                                                                                   
    
	public function createWorklog(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');
		$duration = (string)$this->request->getParam('duration', '');

		if ($entityType === '' || $entityId === '' || $duration === '') {
			return new JSONResponse(
				['message' => 'entityType, entityId and duration are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                                                                      
		                                                                 
		                                                                       
		                
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$data = [
			'duration' => $duration,
			'description' => $this->request->getParam('description'),
			'date' => $this->request->getParam('date'),
		];

		try {
			$created = $this->service->createWorklog(
				entityType: $entityType,
				entityId: $entityId,
				data: $data
			);
			return new JSONResponse($created, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to create worklog',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to create worklog'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                     
}           
